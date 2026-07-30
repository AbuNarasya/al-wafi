<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\ActivityLog;
use App\Models\Jenjang;
use App\Models\Pendaftaran;
use App\Models\RiwayatTingkat;
use App\Models\Santri;
use App\Services\Ppsb\Tahap;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * KENAIKAN TINGKAT & KELULUSAN MASSAL — dalam satu jenjang, serentak satu angkatan.
 *
 * Sebelum ini yang ada hanya aksi `set-tingkat`: mengubah kolom `santri.tingkat`
 * satu santri per kali, tanpa memajukan `tahun_ajaran_berjalan` dan tanpa menulis
 * `riwayat_tingkat`. Akibatnya riwayat bolong di tahun-tahun dalam satu jenjang,
 * dan tarif SPP santri yang naik tingkat tetap memakai tarif tahun masuknya.
 *
 * Yang DIPISAH dari sini (sengaja):
 *  • naik JENJANG → `PendaftaranLanjutanService`, karena harus lewat seleksi PPSB;
 *  • penagihan daftar ulang → modul massalnya sendiri, dijalankan SESUDAH kenaikan.
 *    Satu kesalahan tarif tak boleh memaksa membatalkan seluruh kenaikan kelas.
 *
 * Empat keputusan per santri:
 *  • `naik`      — tingkat +1, tahun berjalan maju;
 *  • `mengulang` — tingkat tetap, tahun berjalan TETAP MAJU (tahunnya berganti);
 *  • `lulus`     — status → alumni + tanggal lulus. Hanya di tingkat terakhir;
 *  • `lewati`    — tak disentuh.
 */
class KenaikanTingkatService
{
    public const NAIK = 'naik';

    public const MENGULANG = 'mengulang';

    public const LULUS = 'lulus';

    public const LEWATI = 'lewati';

    /** Label keputusan untuk layar. */
    public const KEPUTUSAN = [
        self::NAIK => 'Naik tingkat',
        self::MENGULANG => 'Mengulang',
        self::LULUS => 'Lulus (alumni)',
        self::LEWATI => 'Lewati',
    ];

    /**
     * Susun daftar usulan. Tidak menulis apa pun.
     *
     * @return array{baris:list<array<string,mixed>>, ringkas:array<string,int>, tingkat_terakhir:int}
     */
    public function pratinjau(array $filter): array
    {
        $ta = (string) ($filter['tahun_ajaran'] ?? '');
        $jenjang = (string) ($filter['kode_jenjang'] ?? '');
        if ($ta === '' || $jenjang === '') {
            throw new AppException(422, 'Tahun ajaran tujuan & jenjang wajib dipilih.');
        }

        $terakhir = TarifService::tingkatTerakhir($jenjang);
        $punyaLanjutan = (bool) Jenjang::find($jenjang)?->kode_jenjang_lanjutan;

        $santri = Santri::where('kode_jenjang', $jenjang)
            ->where('status', 'aktif')
            ->when(($filter['tingkat'] ?? '') !== '', fn ($q) => $q->where('tingkat', $filter['tingkat']))
            ->orderBy('tingkat')->orderBy('nama')
            ->get(['id', 'nama', 'nis', 'no_pendaftaran', 'tingkat', 'kode_jenjang', 'tahun_ajaran', 'tahun_ajaran_berjalan']);

        $baris = [];
        $ringkas = [self::NAIK => 0, self::MENGULANG => 0, self::LULUS => 0, self::LEWATI => 0];
        foreach ($santri as $s) {
            $usul = $this->usulkan($s, $ta, $terakhir, $punyaLanjutan);
            $ringkas[$usul['usul']]++;
            $baris[] = [
                'id' => $s->id, 'nama' => $s->nama, 'nis' => $s->nis, 'no_pendaftaran' => $s->no_pendaftaran,
                'tingkat' => $s->tingkat, 'angkatan' => $s->tahun_ajaran, 'ta_berjalan' => $s->taBerjalan(),
            ] + $usul;
        }

        return ['baris' => $baris, 'ringkas' => $ringkas, 'tingkat_terakhir' => $terakhir];
    }

    /**
     * Usulan + pilihan yang sah untuk seorang santri.
     *
     * @return array{usul:string, pilihan:list<string>, alasan:?string}
     */
    private function usulkan(Santri $s, string $taTujuan, int $terakhir, bool $punyaLanjutan): array
    {
        $tingkat = (int) $s->tingkat;

        if ($s->taBerjalan() === $taTujuan) {
            return ['usul' => self::LEWATI, 'pilihan' => [self::LEWATI],
                'alasan' => "Sudah berada di T.A {$taTujuan} — kenaikannya sudah pernah dijalankan."];
        }
        if ($tingkat < 1) {
            return ['usul' => self::LEWATI, 'pilihan' => [self::LEWATI],
                'alasan' => 'Tingkat santri ini belum terisi. Lengkapi dulu di data santri.'];
        }
        if ($terakhir < 1) {
            return ['usul' => self::LEWATI, 'pilihan' => [self::LEWATI],
                'alasan' => "Jumlah tingkat jenjang \"{$s->kode_jenjang}\" belum diisi di master Jenjang."];
        }

        if ($tingkat < $terakhir) {
            return ['usul' => self::NAIK, 'pilihan' => [self::NAIK, self::MENGULANG, self::LEWATI], 'alasan' => null];
        }

        // Tingkat terakhir. Kalau jenjangnya punya lanjutan, kelanjutannya lewat
        // PPSB — bukan di sini. Lulus tetap ditawarkan: tak semua santri melanjutkan.
        return [
            'usul' => $punyaLanjutan ? self::LEWATI : self::LULUS,
            'pilihan' => [self::LULUS, self::MENGULANG, self::LEWATI],
            'alasan' => $punyaLanjutan
                ? 'Tingkat terakhir & jenjang ini punya lanjutan — yang melanjutkan didaftarkan lewat '
                    .'Pendaftaran Lanjutan di halaman santri. Pilih "Lulus" hanya bagi yang tidak melanjutkan.'
                : 'Tingkat terakhir jenjang terakhir — kelulusan.',
        ];
    }

    /**
     * Jalankan keputusan petugas.
     *
     * `$keputusan` = [idSantri => 'naik'|'mengulang'|'lulus'|'lewati'].
     *
     * Satu transaksi untuk seluruh batch: satu angkatan naik bersama, dan angkatan
     * yang naik separuh jauh lebih sulit dibereskan daripada batch yang diulang.
     *
     * @return array{naik:int,mengulang:int,lulus:int,batch:string}
     */
    public function eksekusi(string $taTujuan, array $keputusan, int $idPengguna, array $opsi = []): array
    {
        $sah = array_keys(self::KEPUTUSAN);
        $garap = [];
        foreach ($keputusan as $idSantri => $pilih) {
            if (in_array($pilih, $sah, true) && $pilih !== self::LEWATI) {
                $garap[(int) $idSantri] = $pilih;
            }
        }
        if ($garap === []) {
            throw new AppException(422, 'Tidak ada santri yang dipilih untuk dinaikkan atau diluluskan.');
        }

        $tanggalLulus = $opsi['tanggal_lulus'] ?? Carbon::now()->toDateString();
        $batch = 'KENAIKAN-'.now()->format('YmdHis');

        return DB::transaction(function () use ($garap, $taTujuan, $idPengguna, $tanggalLulus, $batch) {
            $hitung = [self::NAIK => 0, self::MENGULANG => 0, self::LULUS => 0];
            $santri = Santri::whereIn('id', array_keys($garap))->get()->keyBy('id');

            foreach ($garap as $idSantri => $pilih) {
                $s = $santri[$idSantri] ?? null;
                if (! $s) {
                    throw new AppException(404, "Santri #{$idSantri} tidak ditemukan.");
                }
                if ($s->status !== 'aktif') {
                    throw new AppException(422, "Santri {$s->nama} tidak berstatus aktif.");
                }
                if ($s->taBerjalan() === $taTujuan) {
                    throw new AppException(422, "Santri {$s->nama} sudah berada di T.A {$taTujuan} — "
                        .'kenaikannya sudah pernah dijalankan.');
                }

                $terakhir = TarifService::tingkatTerakhir($s->kode_jenjang);
                $tingkat = (int) $s->tingkat;

                match ($pilih) {
                    self::NAIK => $this->naikkan($s, $tingkat, $terakhir, $taTujuan),
                    self::MENGULANG => $this->ulangi($s, $tingkat, $taTujuan),
                    self::LULUS => $this->luluskan($s, $tingkat, $terakhir, $tanggalLulus),
                    default => null,
                };
                $hitung[$pilih]++;
            }

            ActivityLog::create([
                'id_pengguna' => $idPengguna,
                'aksi' => 'kenaikan_tingkat_massal',
                'detail' => json_encode([
                    'batch' => $batch, 'tahun_ajaran_tujuan' => $taTujuan,
                    'naik' => $hitung[self::NAIK], 'mengulang' => $hitung[self::MENGULANG], 'lulus' => $hitung[self::LULUS],
                    'keputusan' => $garap,
                ], JSON_UNESCAPED_UNICODE),
            ]);

            return $hitung + ['batch' => $batch];
        });
    }

    /** Tingkat +1 dan tahun berjalan maju. */
    private function naikkan(Santri $s, int $tingkat, int $terakhir, string $taTujuan): void
    {
        if ($tingkat >= $terakhir) {
            throw new AppException(422, "Santri {$s->nama} sudah di tingkat terakhir {$s->kode_jenjang} "
                .'— ia naik JENJANG (lewat Pendaftaran Lanjutan) atau lulus, bukan naik tingkat.');
        }
        $baru = $tingkat + 1;
        $s->update(['tingkat' => $baru, 'tahun_ajaran_berjalan' => $taTujuan]);
        $this->catatRiwayat($s, $taTujuan, $baru, "Naik dari tingkat {$tingkat}.");
    }

    /**
     * Tingkat TETAP, tetapi tahun berjalan MAJU: tahun ajarannya memang berganti,
     * dan tarif SPP-nya harus mengikuti tahun yang baru meski kelasnya sama.
     */
    private function ulangi(Santri $s, int $tingkat, string $taTujuan): void
    {
        $s->update(['tahun_ajaran_berjalan' => $taTujuan]);
        $this->catatRiwayat($s, $taTujuan, $tingkat, "Mengulang di tingkat {$tingkat}.");
    }

    /** Status → alumni. Tunggakan TIDAK dihapus & tak menghalangi: alumni tetap bisa ditagih. */
    private function luluskan(Santri $s, int $tingkat, int $terakhir, string $tanggalLulus): void
    {
        if ($terakhir > 0 && $tingkat < $terakhir) {
            throw new AppException(422, "Santri {$s->nama} masih di tingkat {$tingkat} dari {$terakhir} "
                ."tingkat {$s->kode_jenjang}, jadi belum bisa diluluskan.");
        }
        Tahap::assertTransisi((string) $s->status, 'alumni');

        $s->update(['status' => 'alumni', 'tanggal_lulus' => $tanggalLulus]);
        // Siklus pendaftaran terbarunya ikut ditutup agar statusnya tak tertinggal
        // di "aktif" — sama seperti yang dilakukan pindahTahap untuk tahap lain.
        Pendaftaran::where('id_santri', $s->id)->orderByDesc('id')->first()?->update(['status' => 'alumni']);
    }

    /** Satu baris riwayat per (santri, T.A) — ditulis ulang bila tahun itu sudah ada. */
    private function catatRiwayat(Santri $s, string $taTujuan, int $tingkat, string $catatan): void
    {
        RiwayatTingkat::updateOrCreate(
            ['id_santri' => $s->id, 'tahun_ajaran' => $taTujuan],
            ['kode_jenjang' => $s->kode_jenjang, 'tingkat' => $tingkat, 'catatan' => $catatan],
        );
    }
}
