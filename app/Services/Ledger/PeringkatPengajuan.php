<?php

namespace App\Services\Ledger;

/**
 * Peringkat empat level BAWAAN. 1 = TERTINGGI.
 *
 * ⚠️ Angka-angka ini BUKAN lagi aturan, hanya penamaan susunan bawaan hasil
 * seeder. Jumlah level kini ditentukan pesantren lewat master Level Pengajuan,
 * dan wewenangnya melekat pada TANDA PERAN di baris masternya
 * (`LevelPengajuan::PERAN`), bukan pada angkanya.
 *
 * Jangan dipakai untuk memutuskan boleh-tidaknya sesuatu — pakai
 * `User::berperanPengajuan('...')`. Menanyakan "apakah peringkatnya 4" akan
 * salah begitu pesantren membuat level kelima atau menghapus salah satunya.
 */
final class PeringkatPengajuan
{
    public const KETUA_YAYASAN = 1;
    public const MUDIR_UMUM = 2;
    public const MUDIR_BAGIAN = 3;
    public const STAFF = 4;

    /** Fungsi (bukan pangkat) yang menjalankan tahap verifikasi dokumen. */
    public const FUNGSI_KEUANGAN = 'keuangan';
}
