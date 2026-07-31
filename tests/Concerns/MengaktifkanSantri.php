<?php

namespace Tests\Concerns;

use App\Models\JadwalPerubahanSantri;
use App\Models\Santri;
use App\Services\Modules\JadwalPerubahanService;
use App\Services\Modules\SantriService;

/**
 * Bantuan fixture: dulu `SantriService::daftarUlang()` mengerjakan semuanya dalam
 * satu panggilan — status, tahun berjalan, riwayat, DAN jurnal akrual.
 *
 * Sejak aktivasi dijadwalkan, keduanya terpisah: tombolnya hanya MENANDAI siap,
 * dan jurnalnya baru terbit saat jadwalnya menyala. Puluhan test hanya butuh
 * "jadikan santri ini aktif beserta akrualnya" — itulah yang dikerjakan di sini,
 * lewat jalur yang SAMA dengan tombol manual "Aktifkan Sekarang" di layar
 * (bukan memanggil `aktifkan()` langsung), supaya baris jadwalnya ikut ditandai
 * diterapkan dan tak menyala lagi belakangan.
 */
trait MengaktifkanSantri
{
    protected function aktifkanSantri(int $idSantri, int $idPengguna): Santri
    {
        $santri = (new SantriService)->siapkanAktivasi($idSantri, $idPengguna);

        // Bila T.A masuknya SUDAH berjalan, siapkanAktivasi() sendiri yang
        // menyalakan jadwalnya — tak ada lagi baris `siap` yang tersisa, dan
        // santrinya sudah aktif. Itu keadaan sebagian besar fixture di sini.
        if ($santri->status === 'aktif') {
            return $santri;
        }

        $jadwal = JadwalPerubahanSantri::where('id_santri', $idSantri)
            ->where('keputusan', 'aktivasi')->where('status', 'siap')->orderByDesc('id')->firstOrFail();

        $hasil = (new JadwalPerubahanService)->terapkanSekarang([$jadwal->id]);
        if ($hasil['gagal'] !== []) {
            $this->fail('aktivasi santri gagal: '.$hasil['gagal'][0]['pesan']);
        }

        return Santri::findOrFail($idSantri);
    }
}
