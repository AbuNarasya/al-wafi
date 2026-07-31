<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * STATUS BARU `siap_aktivasi` — antara `lolos_kesehatan` dan `aktif`.
 *
 * Dulu tombol "Mutasi → Santri Aktif" mengerjakan enam hal sekaligus dalam satu
 * tekan: memindahkan status, mengisi tahun berjalan, menulis riwayat tingkat,
 * menutup siklus pendaftaran, DAN memposting jurnal akrual uang pangkal +
 * perlengkapan. Akibatnya calon yang tuntas berkasnya bulan Mei sudah menjadi
 * santri aktif — ikut terhitung dalam penagihan SPP dan kenaikan tingkat —
 * padahal tahun ajarannya baru mulai 1 Juli, dan pendapatan tahun itu sudah
 * diakui di tahun sebelumnya.
 *
 * Sekarang tekanan tombolnya berhenti di `siap_aktivasi`: keputusannya sudah
 * final, tetapi belum satu pun akibatnya berlaku. Aktivasi sesungguhnya
 * dijadwalkan lewat `jadwal_perubahan_santri` (keputusan `aktivasi`) dan menyala
 * saat tahun ajaran masuknya benar-benar dimulai — sama seperti kenaikan tingkat.
 *
 * JURNALNYA IKUT DITUNDA. Itu yang membuat pengunduran diri tetap sederhana:
 * `SantriService::mengundurkanDiri` membalik akrual HANYA untuk status `aktif`;
 * calon yang mundur pada masa `siap_aktivasi` tak meninggalkan jurnal apa pun
 * yang perlu dibalik, karena memang belum ada.
 */
return new class extends Migration
{
    private const BARU = [
        'calon', 'terbayar', 'terverifikasi', 'diseleksi', 'diterima', 'lolos_kesehatan',
        'siap_aktivasi', 'aktif', 'alumni', 'tidak_lulus', 'gagal_medcheck', 'mengundurkan_diri', 'keluar',
    ];

    private const LAMA = [
        'calon', 'terbayar', 'terverifikasi', 'diseleksi', 'diterima', 'lolos_kesehatan',
        'aktif', 'alumni', 'tidak_lulus', 'gagal_medcheck', 'mengundurkan_diri', 'keluar',
    ];

    public function up(): void
    {
        $this->pasangCek(self::BARU);
    }

    public function down(): void
    {
        // Baris yang terlanjur berstatus baru dikembalikan LEBIH DULU — kalau
        // tidak, constraint lama menolak dipasang dan rollback-nya buntu di
        // tengah jalan. Kembali ke `lolos_kesehatan`, bukan `aktif`: mereka
        // memang belum diaktifkan, dan jurnalnya pun belum pernah terbit.
        DB::table('santri')->where('status', 'siap_aktivasi')->update(['status' => 'lolos_kesehatan']);
        DB::table('pendaftaran')->where('status', 'siap_aktivasi')->update(['status' => 'lolos_kesehatan']);
        DB::table('jadwal_perubahan_santri')->where('keputusan', 'aktivasi')->delete();

        $this->pasangCek(self::LAMA);
    }

    /** Enum Laravel di PostgreSQL = varchar + CHECK; nilainya diganti dengan memasang ulang. */
    private function pasangCek(array $nilai): void
    {
        $daftar = implode(',', array_map(fn ($v) => "'{$v}'", $nilai));
        DB::statement('ALTER TABLE santri DROP CONSTRAINT IF EXISTS santri_status_check');
        DB::statement("ALTER TABLE santri ADD CONSTRAINT santri_status_check CHECK (status IN ({$daftar}))");
    }
};
