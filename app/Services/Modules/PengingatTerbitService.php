<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\BatchTagihan;
use App\Models\HakAksesModul;
use App\Models\JadwalPengingatTerbit;
use App\Models\KonfirmasiPengingatTerbit;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * PENGINGAT PENERBITAN — menepuk bahu petugas, tidak menerbitkan apa pun.
 *
 * Ia melengkapi rilis terjadwal dari arah berlawanan: rilis terjadwal menjaga
 * agar yang SUDAH diotorisasi tetap terbit walau petugasnya libur; pengingat
 * ini menjaga agar drafnya memang disusun pada waktunya.
 *
 * ══ TUGAS, BUKAN KABAR ══
 * Notifikasinya tak bisa dipadamkan dengan "tandai dibaca". Ia reda hanya bila
 * pekerjaannya benar-benar terlihat sudah dilakukan — lihat masihMenunggu().
 */
class PengingatTerbitService
{
    public const JENIS_NOTIF = 'pengingat_terbit';

    /**
     * Hak modul yang menandai "orang inilah yang menerbitkan". Sengaja sama
     * dengan peta di BatchTagihanController: yang diingatkan harus orang yang
     * memang bisa mengerjakannya.
     */
    private const HAK = [
        'spp' => ['spp', 'ubah'],
        'daftar_ulang' => ['tagihan-massal', 'buat'],
        'tagihan_lain' => ['tagihan-lain', 'buat'],
    ];

    /** Status batch yang berarti "pekerjaannya sudah dikerjakan". */
    private const SUDAH_DIKERJAKAN = ['diotorisasi', 'dirilis', 'sebagian'];

    // ══════════════════════════════════════════════════════════════════
    //  PENGIRIMAN
    // ══════════════════════════════════════════════════════════════════

    /**
     * Kirim pengingat yang jatuh pada hari ini.
     *
     * @return array{terkirim:int, jadwal:list<array<string,mixed>>}
     */
    public function kirim(?Carbon $hari = null): array
    {
        $hari = ($hari ?? Carbon::today())->startOfDay();
        $ringkas = ['terkirim' => 0, 'jadwal' => []];

        foreach (JadwalPengingatTerbit::where('aktif', true)->get() as $jadwal) {
            $periode = $this->periodeYangJatuhHariIni($jadwal, $hari);
            if ($periode === null) {
                continue;
            }
            // Sudah dikerjakan sebelum sempat ditepuk — jangan menagih pekerjaan
            // yang sudah selesai. Ini yang membuat pengingat tak pernah jadi
            // gangguan bagi petugas yang rajin.
            if (! $this->masihPerlu($jadwal, $periode)) {
                continue;
            }

            $n = $this->kirimSatu($jadwal, $periode);
            $ringkas['terkirim'] += $n;
            $ringkas['jadwal'][] = ['id' => $jadwal->id, 'judul' => $jadwal->judul,
                'periode' => $periode, 'terkirim' => $n];
        }

        return $ringkas;
    }

    private function kirimSatu(JadwalPengingatTerbit $jadwal, string $periode): int
    {
        $ref = self::ref($jadwal->id, $periode);

        // Perintahnya bisa dijalankan dua kali dalam sehari (cron telat lalu
        // disusul pemicu manual). Notifikasi yang sama tak boleh berlipat.
        if (Notification::where('jenis', self::JENIS_NOTIF)->where('ref_id', $ref)->exists()) {
            return 0;
        }

        $penerima = $this->penerima($jadwal->modul);
        if ($penerima === []) {
            return 0;
        }

        $pesan = "Waktunya menyusun & memeriksa draft {$jadwal->labelModul()} untuk {$periode}."
            .($jadwal->catatan ? ' '.$jadwal->catatan : '');

        return (new NotificationService)->kirim(array_map(fn ($id) => [
            'id_pengguna' => $id,
            'judul' => $jadwal->judul,
            'pesan' => $pesan,
            'jenis' => self::JENIS_NOTIF,
            'ref_jenis' => 'JadwalPengingatTerbit',
            'ref_id' => $ref,
        ], $penerima))['terkirim'];
    }

    /**
     * Siapa yang ditepuk: pemegang hak modul penerbitnya.
     *
     * Admin ikut, dan bukan sekadar kemurahan hati — hak akses di sini diberikan
     * manual satu per satu, dan modul yang belum sempat diberikan ke siapa pun
     * akan membuat pengingatnya menguap tanpa ada yang tahu. Admin adalah
     * jaring terakhirnya.
     *
     * @return list<int>
     */
    public function penerima(string $modul): array
    {
        [$kode, $aksi] = self::HAK[$modul] ?? [null, null];
        if (! $kode) {
            return [];
        }

        $ids = HakAksesModul::query()
            ->where('kode_modul', $kode)->where($aksi, true)
            ->whereHas('pengguna', fn ($q) => $q->where('status', 'aktif'))
            ->pluck('id_pengguna')->all();

        $ids = array_merge($ids, User::where('is_admin', true)->where('status', 'aktif')
            ->pluck('id_pengguna')->all());

        return array_values(array_unique(array_map('intval', $ids)));
    }

    // ══════════════════════════════════════════════════════════════════
    //  REDANYA
    // ══════════════════════════════════════════════════════════════════

    /**
     * Apakah tugasnya masih menunggu? Dipanggil NotificationService saat
     * merapikan lonceng.
     *
     * Dua jalan padam, keduanya disengaja:
     *  • petugas menekan "sudah saya kerjakan" — ini yang diminta user;
     *  • batch untuk periode itu memang sudah diotorisasi — supaya tak perlu
     *    mengonfirmasi dua kali hal yang sama.
     */
    public function masihMenunggu(string $ref): bool
    {
        [$idJadwal, $periode] = self::pecahRef($ref);
        $jadwal = JadwalPengingatTerbit::find($idJadwal);

        // Jadwalnya dihapus → tugasnya ikut kehilangan arti.
        return $jadwal ? $this->masihPerlu($jadwal, $periode) : false;
    }

    private function masihPerlu(JadwalPengingatTerbit $jadwal, string $periode): bool
    {
        if (KonfirmasiPengingatTerbit::where('id_jadwal', $jadwal->id)->where('periode', $periode)->exists()) {
            return false;
        }

        return ! $this->adaBatchnya($jadwal, $periode);
    }

    /** Sudah ada batch untuk periode itu yang setidaknya sudah diotorisasi? */
    private function adaBatchnya(JadwalPengingatTerbit $jadwal, string $periode): bool
    {
        $q = BatchTagihan::where('modul', $jadwal->modul)
            ->whereIn('status', self::SUDAH_DIKERJAKAN);

        return match ($jadwal->modul) {
            // Periode SPP tersimpan di parameter batch-nya.
            'spp' => $q->where('parameter->periode', $periode)->exists(),

            // Daftar ulang ber-tahun ajaran, bukan ber-bulan. Periode tahunan
            // "2027" dicocokkan dengan T.A yang DIMULAI tahun itu ("2027/2028").
            'daftar_ulang' => $q->where('parameter->tahun_ajaran', 'like', $periode.'/%')->exists(),

            'tagihan_lain' => $q->where('parameter->kode_jenis', $jadwal->kode_jenis)
                ->where('parameter->periode', $periode)->exists(),

            default => false,
        };
    }

    /** Petugas menyatakan pekerjaannya sudah dilakukan. */
    public function konfirmasi(int $idJadwal, string $periode, int $idPengguna): KonfirmasiPengingatTerbit
    {
        $jadwal = JadwalPengingatTerbit::find($idJadwal);
        if (! $jadwal) {
            throw new AppException(404, 'Jadwal pengingat tidak ditemukan.');
        }

        return KonfirmasiPengingatTerbit::firstOrCreate(
            ['id_jadwal' => $idJadwal, 'periode' => $periode],
            ['oleh' => $idPengguna, 'pada' => now()],
        );
    }

    /**
     * Pengingat yang masih menunggu seorang pengguna — dipakai spanduk di layar
     * Batch Tagihan, supaya tugasnya tak hanya hidup di dalam lonceng.
     *
     * @return list<array{id_jadwal:int,periode:string,judul:string,pesan:string}>
     */
    public function terbukaUntuk(int $idPengguna): array
    {
        $rows = Notification::where('id_pengguna', $idPengguna)
            ->where('jenis', self::JENIS_NOTIF)->where('dibaca', false)
            ->orderByDesc('id')->get();

        $hasil = [];
        foreach ($rows as $n) {
            if (! $n->ref_id || ! $this->masihMenunggu($n->ref_id)) {
                continue;
            }
            [$idJadwal, $periode] = self::pecahRef($n->ref_id);
            $hasil[] = ['id_jadwal' => $idJadwal, 'periode' => $periode,
                'judul' => $n->judul, 'pesan' => $n->pesan];
        }

        return $hasil;
    }

    // ══════════════════════════════════════════════════════════════════
    //  KALENDER
    // ══════════════════════════════════════════════════════════════════

    /**
     * Periode yang pengingatnya jatuh tepat pada `$hari`, atau null.
     *
     * Dua periode diperiksa — yang berjalan dan yang berikutnya — karena
     * `hari_sebelum` bisa melempar hari kirimnya ke bulan (atau tahun) sebelum
     * periodenya. Diingatkan 3 hari sebelum 2 Agustus berarti ditepuk 30 Juli.
     */
    private function periodeYangJatuhHariIni(JadwalPengingatTerbit $jadwal, Carbon $hari): ?string
    {
        foreach ($this->periodeKandidat($jadwal, $hari) as $periode) {
            if ($this->tanggalKirim($jadwal, $periode)->isSameDay($hari)) {
                return $periode;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function periodeKandidat(JadwalPengingatTerbit $jadwal, Carbon $hari): array
    {
        return $jadwal->irama === 'tahunan'
            ? [$hari->format('Y'), $hari->copy()->addYear()->format('Y')]
            : [$hari->format('Y-m'), $hari->copy()->addMonthNoOverflow()->format('Y-m')];
    }

    public function tanggalKirim(JadwalPengingatTerbit $jadwal, string $periode): Carbon
    {
        $awal = $jadwal->irama === 'tahunan'
            ? Carbon::create((int) $periode, $jadwal->bulan ?: 1, 1)
            : Carbon::parse($periode.'-01');

        // Tanggal 0 = hari terakhir. Tanggal yang melewati panjang bulan (31 di
        // bulan 30 hari) juga dijatuhkan ke hari terakhir — kalau tidak,
        // pengingatnya hilang diam-diam di bulan-bulan pendek.
        $hariTerakhir = (int) $awal->copy()->endOfMonth()->day;
        $tanggal = $jadwal->tanggal === JadwalPengingatTerbit::AKHIR_BULAN
            ? $hariTerakhir
            : min($jadwal->tanggal, $hariTerakhir);

        return $awal->copy()->setDay($tanggal)->subDays($jadwal->hari_sebelum)->startOfDay();
    }

    public static function ref(int $idJadwal, string $periode): string
    {
        return $idJadwal.':'.$periode;
    }

    /** @return array{0:int,1:string} */
    public static function pecahRef(string $ref): array
    {
        [$id, $periode] = array_pad(explode(':', $ref, 2), 2, '');

        return [(int) $id, (string) $periode];
    }
}
