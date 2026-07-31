<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\JalurPendaftaran;
use App\Models\PotonganGelombang;
use App\Models\Santri;
use App\Models\TahunAjaran;
use App\Models\TargetSantri;
use App\Models\TarifBiaya;
use Illuminate\Support\Facades\DB;

/**
 * Master Tahun Ajaran. kode ("2026/2027") dirujuk sebagai string oleh
 * jenis_biaya, potongan_gelombang, target_santri, dan santri — karena itu kode
 * tak bisa diubah dan TA yang terpakai tak bisa dihapus. Maksimal satu TA aktif
 * menjadi default_pendaftaran.
 *
 * Jalur pendaftaran TIDAK termasuk: ia berlaku lintas tahun ajaran.
 */
class TahunAjaranService
{
    public function list()
    {
        return TahunAjaran::orderByDesc('kode')->get();
    }

    public function get(int $id): TahunAjaran
    {
        $row = TahunAjaran::find($id);
        if (! $row) {
            throw new AppException(404, 'Tahun ajaran tidak ditemukan.');
        }

        return $row;
    }

    public function create(array $data): TahunAjaran
    {
        $this->periksaTanggal($data);

        return DB::transaction(function () use ($data) {
            $row = TahunAjaran::create($data);
            if ($row->default_pendaftaran) {
                $this->jadikanSatuSatunyaDefault($row);
            }

            return $row;
        });
    }

    public function update(int $id, array $data): TahunAjaran
    {
        $row = $this->get($id);
        unset($data['kode']); // kode dirujuk tabel lain — tidak boleh berubah
        // Tanggal yang tak ikut dikirim diambil dari baris yang ada, supaya
        // menyunting salah satunya saja tetap diperiksa terhadap pasangannya.
        $this->periksaTanggal($data + [
            'tanggal_mulai' => $row->tanggal_mulai?->toDateString(),
            'tanggal_selesai' => $row->tanggal_selesai?->toDateString(),
        ], $row->id);
        if (($data['status'] ?? $row->status) === 'nonaktif' && ($data['default_pendaftaran'] ?? $row->default_pendaftaran)) {
            throw new AppException(422, 'Tahun ajaran nonaktif tidak bisa menjadi default pendaftaran.');
        }

        return DB::transaction(function () use ($row, $data) {
            $row->update($data);
            if ($row->default_pendaftaran) {
                $this->jadikanSatuSatunyaDefault($row);
            }

            return $row->refresh();
        });
    }

    public function remove(int $id): void
    {
        $row = $this->get($id);
        $dipakai = [
            // Jenis biaya TIDAK lagi dihitung: sejak tarif pindah ke tabelnya
            // sendiri, jenis biaya hanya memegang akun dan berlaku lintas T.A.
            // Yang merujuk tahun ajaran sekarang adalah sel tarifnya.
            'sel tarif' => TarifBiaya::where('tahun_ajaran', $row->kode)->count(),
            // Jalur pendaftaran TIDAK dihitung: sejak 2026-07-28 jalur berlaku
            // lintas tahun ajaran, jadi tak pernah merujuk satu T.A.
            'potongan gelombang' => PotonganGelombang::where('tahun_ajaran', $row->kode)->count(),
            'target santri' => TargetSantri::where('tahun_ajaran', $row->kode)->count(),
            'santri' => Santri::where('tahun_ajaran', $row->kode)->count(),
        ];
        $ada = array_filter($dipakai);
        if ($ada !== []) {
            $rincian = implode(', ', array_map(fn ($n, $t) => "{$n} {$t}", $ada, array_keys($ada)));
            throw new AppException(409, "Tahun ajaran {$row->kode} masih dirujuk ({$rincian}). Nonaktifkan saja bila sudah tidak dipakai.");
        }
        $row->delete();
    }

    /** @return array<string,string> kode => kode, hanya TA aktif (untuk dropdown). */
    public function opsiAktif(): array
    {
        return TahunAjaran::where('status', 'aktif')->orderByDesc('kode')
            ->pluck('kode', 'kode')->all();
    }

    /** TA default form registrasi; fallback TA aktif terbaru. */
    public function defaultPendaftaran(): ?TahunAjaran
    {
        return TahunAjaran::where('status', 'aktif')->where('default_pendaftaran', true)->first()
            ?? TahunAjaran::where('status', 'aktif')->orderByDesc('kode')->first();
    }

    /**
     * TAHUN AJARAN BERJALAN — diturunkan dari RENTANG TANGGAL, bukan dari flag.
     *
     * Jangan tertukar dengan `defaultPendaftaran()`: yang itu tahun yang sedang
     * DIREKRUT (pendaftaran 2027/2028 dibuka pada 2026), sedangkan ini tahun yang
     * sedang DIJALANI. Keduanya normalnya berbeda, dan mencampurnya membuat SPP
     * bulan berjalan tercap tahun ajaran yang belum dimulai.
     *
     * Bisa null: kalender bisa berlubang bila satu tahun belum dibuat.
     */
    public function berjalan(?string $tanggal = null): ?TahunAjaran
    {
        return $this->yangMemuat($tanggal ?? now()->toDateString());
    }

    /** Tahun ajaran yang rentangnya memuat sebuah tanggal. */
    public function yangMemuat(string $tanggal): ?TahunAjaran
    {
        return TahunAjaran::where('status', 'aktif')
            ->whereDate('tanggal_mulai', '<=', $tanggal)
            ->whereDate('tanggal_selesai', '>=', $tanggal)
            ->orderBy('tanggal_mulai')
            ->first();
    }

    /**
     * Tahun ajaran sebuah PERIODE tagihan ("2026-07").
     *
     * Yang dipakai tanggal 1 bulan itu: periode adalah satu bulan penuh, dan
     * batas tahun ajaran selalu jatuh di pergantian bulan (1 Juli / 30 Juni),
     * sehingga awal bulan sudah cukup menentukan tahunnya.
     */
    public function yangMemuatPeriode(string $periode): ?TahunAjaran
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $periode)) {
            throw new AppException(422, "Periode \"{$periode}\" tidak berbentuk YYYY-MM.");
        }

        return $this->yangMemuat($periode.'-01');
    }

    /**
     * Penjaga PPSB: transaksi boleh untuk tahun berjalan atau yang AKAN DATANG,
     * tak boleh mundur ke tahun yang sudah lewat.
     *
     * Dibandingkan lewat TANGGAL MULAI, bukan kode: kode berbentuk "2026/2027"
     * kebetulan terurut benar secara abjad, tetapi itu kebetulan penamaan — yang
     * benar-benar menentukan urutan adalah kalendernya.
     */
    public function assertTidakMundur(string $kode, string $konteks = 'Transaksi ini'): TahunAjaran
    {
        $row = $this->pastikanAktif($kode);
        $berjalan = $this->berjalan();

        // Tanpa tahun berjalan (kalender berlubang) tak ada yang bisa dibandingkan.
        // Dibiarkan lewat, bukan ditolak: yang salah masternya, dan menolak di
        // sini hanya memindahkan kebingungan ke layar yang tak bisa memperbaikinya.
        if (! $berjalan || $row->kode === $berjalan->kode) {
            return $row;
        }

        if ($row->tanggal_mulai < $berjalan->tanggal_mulai) {
            throw new AppException(422, "{$konteks} tidak bisa dicatat untuk tahun ajaran {$row->kode} "
                ."yang sudah lewat. Tahun ajaran berjalan adalah {$berjalan->kode}; "
                .'yang boleh dipilih hanya tahun berjalan atau tahun berikutnya.');
        }

        return $row;
    }

    /**
     * Tahun ajaran SETELAH tahun berjalan, menurut kalender. Null bila tahun
     * berikutnya belum dibuat — atau bila tak ada tahun berjalan sama sekali.
     */
    public function berikutnya(?string $tanggal = null): ?TahunAjaran
    {
        $berjalan = $this->berjalan($tanggal);
        if (! $berjalan) {
            return null;
        }

        return TahunAjaran::where('status', 'aktif')
            ->whereNotNull('tanggal_mulai')
            ->whereDate('tanggal_mulai', '>', $berjalan->tanggal_mulai)
            ->orderBy('tanggal_mulai')->first();
    }

    /**
     * Penjaga KENAIKAN TINGKAT: tujuannya hanya boleh tahun BERJALAN (kenaikan
     * yang telat dijalankan) atau tahun BERIKUTNYA (persiapan sebelum tahun
     * barunya mulai — kenaikan memang dikerjakan Juni untuk Juli).
     *
     * Sengaja LEBIH KETAT daripada assertTidakMundur() yang dipakai PPSB. PPSB
     * merekrut orang yang belum ada, jadi melompat jauh ke depan tak merusak
     * apa pun. Kenaikan memindahkan santri yang SUDAH ada: melompat dua tahun
     * berarti ada satu tahun yang tak pernah dijalani, dan `riwayat_tingkat`
     * bolong tanpa bisa diperbaiki dari layar mana pun.
     *
     * Kalender berlubang (tak ada tahun berjalan) dibiarkan lewat, sama seperti
     * assertTidakMundur(): yang salah masternya, dan menolak di sini hanya
     * memindahkan kebingungan ke layar yang tak bisa memperbaikinya.
     */
    public function assertBerjalanAtauBerikutnya(string $kode, string $konteks = 'Transaksi ini'): TahunAjaran
    {
        $row = $this->pastikanAktif($kode);
        $berjalan = $this->berjalan();
        if (! $berjalan) {
            return $row;
        }

        $berikutnya = $this->berikutnya();
        if (in_array($row->kode, array_filter([$berjalan->kode, $berikutnya?->kode]), true)) {
            return $row;
        }

        throw new AppException(422, "{$konteks} hanya boleh untuk tahun ajaran berjalan ({$berjalan->kode})"
            .($berikutnya ? " atau berikutnya ({$berikutnya->kode})" : '')
            .", bukan {$row->kode}.");
    }

    /** Pastikan kode TA ada & aktif; kembalikan barisnya. */
    public function pastikanAktif(string $kode): TahunAjaran
    {
        $row = TahunAjaran::where('kode', $kode)->first();
        if (! $row) {
            throw new AppException(422, "Tahun ajaran \"{$kode}\" tidak terdaftar. Tambahkan dulu di menu PPSB → Tahun Ajaran.");
        }
        if ($row->status !== 'aktif') {
            throw new AppException(422, "Tahun ajaran {$kode} berstatus nonaktif.");
        }

        return $row;
    }

    private function jadikanSatuSatunyaDefault(TahunAjaran $row): void
    {
        TahunAjaran::where('id', '!=', $row->id)->where('default_pendaftaran', true)
            ->update(['default_pendaftaran' => false]);
    }

    /**
     * Kalender tahun ajaran harus RAPAT — itulah jangkar yang dipakai
     * `berjalan()` dan seluruh penjaga di bawahnya.
     *
     * Dulu penjaganya hanya menolak `selesai < mulai`, sehingga tanggal yang SAMA
     * lolos: satu tahun ajaran berumur sehari, dan sejak itu tak ada lagi tahun
     * berjalan sepanjang sisa tahun — tanpa satu pun peringatan.
     *
     * @param  int|null  $abaikanId  baris yang sedang disunting (jangan dibandingkan dengan dirinya sendiri)
     */
    private function periksaTanggal(array $data, ?int $abaikanId = null): void
    {
        $mulai = $data['tanggal_mulai'] ?? null;
        $selesai = $data['tanggal_selesai'] ?? null;
        if (empty($mulai) || empty($selesai)) {
            return;
        }

        if ($selesai <= $mulai) {
            throw new AppException(422, 'Tanggal selesai harus SETELAH tanggal mulai — '
                .'tahun ajaran yang mulai dan selesai di hari yang sama membuat tak ada tahun berjalan sama sekali.');
        }

        // Tumpang tindih membuat satu tanggal dimiliki dua tahun ajaran, dan
        // `berjalan()` akan memilih salah satunya secara sewenang-wenang.
        $bentrok = TahunAjaran::query()
            ->when($abaikanId, fn ($q) => $q->where('id', '!=', $abaikanId))
            ->whereDate('tanggal_mulai', '<=', $selesai)
            ->whereDate('tanggal_selesai', '>=', $mulai)
            ->first();

        if ($bentrok) {
            throw new AppException(422, "Rentang tanggalnya bertumpang tindih dengan tahun ajaran {$bentrok->kode} "
                ."({$bentrok->tanggal_mulai->format('d/m/Y')} – {$bentrok->tanggal_selesai->format('d/m/Y')}). "
                .'Satu tanggal hanya boleh dimiliki satu tahun ajaran.');
        }
    }

    /**
     * Celah pada kalender — dilaporkan, bukan ditolak.
     *
     * Menolaknya akan menghalangi pesantren yang memang belum membuat tahun-tahun
     * lama. Yang berbahaya adalah celahnya TAK TERLIHAT: hari yang jatuh di celah
     * tak punya tahun berjalan, dan penerbitan SPP berhenti tanpa sebab yang jelas.
     *
     * @return list<string> keterangan tiap celah, terurut
     */
    public function celahKalender(): array
    {
        $baris = TahunAjaran::where('status', 'aktif')
            ->whereNotNull('tanggal_mulai')->whereNotNull('tanggal_selesai')
            ->orderBy('tanggal_mulai')->get();

        $celah = [];
        foreach ($baris as $i => $t) {
            $berikut = $baris[$i + 1] ?? null;
            if (! $berikut) {
                continue;
            }
            $harusMulai = $t->tanggal_selesai->copy()->addDay();
            if ($berikut->tanggal_mulai->gt($harusMulai)) {
                $celah[] = "{$harusMulai->format('d/m/Y')} – {$berikut->tanggal_mulai->copy()->subDay()->format('d/m/Y')} "
                    ."(antara {$t->kode} dan {$berikut->kode})";
            }
        }

        return $celah;
    }
}
