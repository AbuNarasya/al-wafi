<?php

namespace App\Services\Ledger;

/**
 * Peringkat Level Pengajuan. 1 = TERTINGGI. Angkanya stabil, BUKAN namanya
 * (admin bebas mengganti label lewat master Level Pengajuan).
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
