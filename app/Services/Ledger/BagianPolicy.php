<?php

namespace App\Services\Ledger;

/**
 * Kebijakan dimensi BAGIAN — satu sumber kebenaran bagi postJournal (kapan
 * kode_bagian wajib) dan budget service (baris mana = realisasi anggaran).
 *
 * Keputusan user: anggaran hanya memuat kebutuhan KAS (bukan non-kas) dan hanya
 * akun kelompok 5 (Beban), bukan akun 4.
 */
final class BagianPolicy
{
    /** Kelompok akun yang dianggarkan = 5 (Beban). */
    public const KELOMPOK_ANGGARAN = '5';

    /**
     * Modul yang menghasilkan jurnal NON-KAS → tidak dianggarkan, dikecualikan
     * dari kewajiban Bagian & perhitungan realisasi. RekonsiliasiBank TIDAK
     * dikecualikan (biaya admin bank = kas riil).
     */
    public const SUMBER_NON_KAS = ['Depresiasi', 'Accrue', 'TutupBuku'];

    /**
     * Deteksi akun Beban lewat PREFIX kode_coa (konvensi: akun kelompok 5 selalu
     * diawali '5'). Murah — tanpa query, aman di dalam transaksi posting.
     */
    public static function isAkunBeban(string $kodeCoa): bool
    {
        return isset($kodeCoa[0]) && $kodeCoa[0] === self::KELOMPOK_ANGGARAN;
    }

    /** Apakah jurnal dari modul ini dianggarkan (kas riil)? */
    public static function isSumberDianggarkan(string $sumberModul): bool
    {
        return ! in_array($sumberModul, self::SUMBER_NON_KAS, true);
    }

    /** Bagian wajib bila: baris menyentuh akun Beban DAN modulnya kas riil. */
    public static function wajibBagian(string $sumberModul, string $kodeCoa): bool
    {
        return self::isSumberDianggarkan($sumberModul) && self::isAkunBeban($kodeCoa);
    }
}
