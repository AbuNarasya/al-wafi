<?php

namespace App\Services\Ppsb;

use App\Exceptions\AppException;

/**
 * ATURAN DOMPET — wadi'ah (titipan, LIABILITAS). Perpindahan antar-dompet =
 * reklasifikasi antar-liabilitas (kas tak bergerak). Arah: Wali→Santri boleh,
 * Santri→Wali DITOLAK (dana yang diserahkan ke santri tak bisa ditarik balik).
 */
final class DompetPolicy
{
    private const ARAH_SAH = [
        ['dari' => 'wali', 'ke' => 'santri'],
        ['dari' => 'wali', 'ke' => 'tabungan'],
        ['dari' => 'santri', 'ke' => 'tabungan'],
    ];

    private const LABEL = ['wali' => 'Dompet Wali', 'santri' => 'Dompet Santri', 'tabungan' => 'Tabungan Santri'];

    /** Akun COA titipan per dompet (semua LIABILITAS, saldo normal kredit). */
    public const COA_TITIPAN = [
        'wali' => '2.1.01.009',
        'santri' => '2.1.01.006',
        'tabungan' => '2.1.01.007',
    ];

    public static function labelDompet(string $p): string
    {
        return self::LABEL[$p] ?? $p;
    }

    public static function assertArahSah(string $dari, string $ke): void
    {
        if ($dari === $ke) {
            throw new AppException(422, 'Asal dan tujuan pemindahan tidak boleh sama.');
        }
        $sah = false;
        foreach (self::ARAH_SAH as $a) {
            if ($a['dari'] === $dari && $a['ke'] === $ke) {
                $sah = true;
                break;
            }
        }
        if (! $sah) {
            if ($dari === 'santri' && $ke === 'wali') {
                throw new AppException(422, 'Dana di Dompet Santri tidak bisa dikembalikan ke Dompet Wali. Titipan yang sudah diserahkan kepada santri hanya boleh dipakai olehnya.');
            }
            if ($dari === 'tabungan') {
                throw new AppException(422, 'Penarikan Tabungan Santri belum tersedia lewat menu ini. Tabungan hanya bisa diisi, bukan dipindahkan.');
            }
            throw new AppException(422, 'Pemindahan dari '.self::labelDompet($dari).' ke '.self::labelDompet($ke).' tidak diizinkan.');
        }
    }
}
