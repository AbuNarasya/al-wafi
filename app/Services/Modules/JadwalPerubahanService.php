<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\JadwalPerubahanSantri;
use App\Models\Pendaftaran;
use App\Models\RiwayatTingkat;
use App\Models\Santri;
use App\Services\Ppsb\Tahap;
use Illuminate\Support\Facades\DB;

/**
 * PENERAP JADWAL PERUBAHAN SANTRI — satu-satunya tempat perubahan yang sudah
 * ditetapkan benar-benar dinyalakan.
 *
 * Berdiri sendiri, bukan menumpang KenaikanTingkatService: yang dinyalakan di
 * sini bukan hanya kenaikan. `aktivasi` datang dari PPSB (calon yang siap
 * diaktifkan), `melanjutkan` dari pendaftaran lanjutan, sedangkan `naik`,
 * `mengulang`, & `lulus` dari kenaikan massal. Ketiganya menempuh satu pintu
 * yang sama supaya tak ada jalur yang diam-diam berperilaku lain.
 *
 * DIPANGGIL DARI DUA ARAH: penjadwal harian (`santri:terapkan-jadwal`) DAN saat
 * halaman kesantrian dibuka. Yang kedua bukan kemewahan — produksi berjalan di
 * paket gratis yang TIDAK punya cron sama sekali, jadi tanpa itu tak ada satu
 * jadwal pun yang akan menyala.
 */
class JadwalPerubahanService
{
    /** Catatan riwayat tingkat per keputusan — dibaca orang, jadi disebut apa adanya. */
    private const CATATAN_RIWAYAT = [
        'naik' => 'Naik tingkat sesuai perubahan yang ditetapkan.',
        'mengulang' => 'Mengulang di tingkat yang sama.',
        'melanjutkan' => 'Naik jenjang lewat pendaftaran lanjutan.',
        'aktivasi' => 'Masuk sebagai santri aktif.',
    ];

    /**
     * Nyalakan jadwal yang tahun ajarannya sudah dimulai.
     *
     * Idempoten: barisnya ditandai `diterapkan`, jadi dipanggil berkali-kali
     * dalam sehari tak mengubah siapa pun dua kali.
     *
     * Satu baris gagal TIDAK membatalkan yang lain — penerap berjalan tanpa
     * seorang pun menunggui, dan menahan seluruh angkatan karena satu santri
     * bermasalah berarti tak ada yang berubah sama sekali. Yang gagal dilaporkan
     * dan tetap berstatus `siap` agar dicoba lagi.
     *
     * @return array{diterapkan:int, gagal:list<array{id_santri:int,pesan:string}>}
     */
    public function terapkanYangJatuhTempo(?string $tanggal = null): array
    {
        return $this->jalankan(
            JadwalPerubahanSantri::jatuhTempo($tanggal)->with('santri')->orderBy('id')->get(),
        );
    }

    /**
     * Nyalakan jadwal TERTENTU sekarang juga, tanpa menunggu tanggalnya — tombol
     * manual "Aktifkan Sekarang" & aktivasi massal.
     *
     * Ada karena tanggal bukan satu-satunya kebenaran: santri yang masuk di
     * tengah tahun ajaran memang harus aktif hari itu juga, dan menunggu 1 Juli
     * berikutnya akan menahannya setahun penuh.
     *
     * @param  list<int>  $idJadwal
     * @return array{diterapkan:int, gagal:list<array{id_santri:int,pesan:string}>}
     */
    public function terapkanSekarang(array $idJadwal): array
    {
        if ($idJadwal === []) {
            throw new AppException(422, 'Tidak ada baris yang dipilih untuk diterapkan.');
        }

        return $this->jalankan(
            JadwalPerubahanSantri::whereIn('id', $idJadwal)->where('status', 'siap')
                ->with('santri')->orderBy('id')->get(),
        );
    }

    /** @param  \Illuminate\Support\Collection<int,JadwalPerubahanSantri>  $jadwal */
    private function jalankan($jadwal): array
    {
        $diterapkan = 0;
        $gagal = [];
        foreach ($jadwal as $j) {
            try {
                DB::transaction(fn () => $this->terapkan($j));
                $diterapkan++;
            } catch (AppException $e) {
                $gagal[] = ['id_santri' => $j->id_santri, 'pesan' => $e->getMessage()];
            }
        }

        return ['diterapkan' => $diterapkan, 'gagal' => $gagal];
    }

    /** Satu jadwal dinyalakan. */
    private function terapkan(JadwalPerubahanSantri $j): void
    {
        $s = $j->santri;
        if (! $s) {
            throw new AppException(404, "Santri #{$j->id_santri} tidak ditemukan.");
        }

        match ($j->keputusan) {
            'aktivasi' => $this->aktifkan($s, $j),
            'lulus' => $this->luluskan($s, $j),
            default => $this->pindahkan($s, $j),
        };

        $j->update(['status' => 'diterapkan', 'diterapkan_pada' => now()]);
    }

    /**
     * AKTIVASI — calon yang sudah siap benar-benar menjadi santri.
     *
     * Inilah saat jurnal akrual uang pangkal & perlengkapannya terbit, bukan
     * saat tombolnya ditekan berbulan-bulan sebelumnya: pendapatan tahun ajaran
     * ini diakui pada tahun ini. Pekerjaannya diserahkan ke SantriService yang
     * memang memegang akrual & penutupan siklus pendaftaran.
     */
    private function aktifkan(Santri $s, JadwalPerubahanSantri $j): void
    {
        if ($s->status !== 'siap_aktivasi') {
            throw new AppException(422, "Santri {$s->nama} tidak lagi berstatus \"Siap Diaktifkan\" "
                .'(sekarang "'.Tahap::labelStatus((string) $s->status).'").');
        }

        (new SantriService)->aktifkan($s, $j->ditetapkan_oleh);
    }

    /**
     * Status → alumni. Tunggakan TIDAK dihapus & tak menghalangi: alumni tetap
     * bisa ditagih.
     *
     * Tanggal lulusnya diambil dari JADWAL — itu tanggal ijazah yang disepakati
     * saat keputusannya ditetapkan, bukan tanggal jadwalnya kebetulan menyala.
     */
    private function luluskan(Santri $s, JadwalPerubahanSantri $j): void
    {
        $this->pastikanAktif($s);
        Tahap::assertTransisi((string) $s->status, 'alumni');

        $s->update([
            'status' => 'alumni',
            'tanggal_lulus' => $j->tanggal_lulus?->toDateString() ?? now()->toDateString(),
        ]);
        // Siklus pendaftaran terbarunya ikut ditutup agar statusnya tak
        // tertinggal di "aktif" — sama seperti pindahTahap untuk tahap lain.
        Pendaftaran::where('id_santri', $s->id)->orderByDesc('id')->first()?->update(['status' => 'alumni']);
    }

    /** Naik / mengulang / melanjutkan — empat kolom yang sama untuk ketiganya. */
    private function pindahkan(Santri $s, JadwalPerubahanSantri $j): void
    {
        $this->pastikanAktif($s);

        $s->update([
            'kode_jenjang' => $j->kode_jenjang_tujuan ?: $s->kode_jenjang,
            'tingkat' => $j->tingkat_tujuan ?? $s->tingkat,
            'jalur' => $j->kode_jalur_tujuan ?: $s->jalur,
            // Yang MAJU adalah tahun berjalan; angkatan tetap tahun masuknya.
            'tahun_ajaran_berjalan' => $j->tahun_ajaran,
        ]);
        $this->catatRiwayat($s->refresh(), $j->tahun_ajaran, (int) $s->tingkat,
            self::CATATAN_RIWAYAT[$j->keputusan] ?? '');
    }

    private function pastikanAktif(Santri $s): void
    {
        if ($s->status !== 'aktif') {
            throw new AppException(422, "Santri {$s->nama} tidak lagi berstatus aktif.");
        }
    }

    /** Satu baris riwayat per (santri, T.A) — ditulis ulang bila tahun itu sudah ada. */
    private function catatRiwayat(Santri $s, string $taTujuan, int $tingkat, string $catatan): void
    {
        RiwayatTingkat::updateOrCreate(
            ['id_santri' => $s->id, 'tahun_ajaran' => $taTujuan],
            ['kode_jenjang' => $s->kode_jenjang, 'tingkat' => $tingkat, 'catatan' => $catatan],
        );
    }

    /** Catatan riwayat untuk aktivasi — dipakai SantriService supaya kalimatnya satu. */
    public function catatRiwayatMasuk(Santri $s, string $tahunAjaran): void
    {
        $this->catatRiwayat($s, $tahunAjaran, (int) $s->tingkat, self::CATATAN_RIWAYAT['aktivasi']);
    }
}
