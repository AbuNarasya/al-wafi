<?php

namespace App\Support;

/**
 * Angka → kata Bahasa Indonesia, untuk kuitansi ("… rupiah"). Nominal uang
 * dibulatkan ke rupiah penuh; pecahan sen diabaikan (kuitansi memakai rupiah).
 */
final class Terbilang
{
    private const SATUAN = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];

    /** Skala 1000^n. Cukup sampai triliun untuk nominal kesantrian. */
    private const SKALA = [
        1_000_000_000_000 => 'triliun',
        1_000_000_000 => 'miliar',
        1_000_000 => 'juta',
        1_000 => 'ribu',
    ];

    /** "1500000" → "satu juta lima ratus ribu". Nol → "nol". */
    public static function dari(string|int|float $angka): string
    {
        $n = (int) round((float) $angka);
        if ($n < 0) {
            return 'minus '.self::dari(-$n);
        }

        // ubah() menyisakan spasi ganda pada bagian yang bernilai nol.
        return trim(preg_replace('/\s+/', ' ', self::ubah($n))) ?: 'nol';
    }

    /** "1500000" → "satu juta lima ratus ribu rupiah". */
    public static function rupiah(string|int|float $angka): string
    {
        return self::dari($angka).' rupiah';
    }

    private static function ubah(int $n): string
    {
        if ($n < 12) {
            return self::SATUAN[$n];
        }
        if ($n < 20) {
            return self::ubah($n - 10).' belas';
        }
        if ($n < 100) {
            return self::ubah(intdiv($n, 10)).' puluh '.self::ubah($n % 10);
        }
        if ($n < 200) {
            return 'seratus '.self::ubah($n - 100);
        }
        if ($n < 1000) {
            return self::ubah(intdiv($n, 100)).' ratus '.self::ubah($n % 100);
        }

        foreach (self::SKALA as $nilai => $nama) {
            if ($n < $nilai) {
                continue;
            }
            // "seribu", bukan "satu ribu" (hanya berlaku untuk ribuan).
            $depan = ($nilai === 1000 && intdiv($n, $nilai) === 1)
                ? 'seribu'
                : self::ubah(intdiv($n, $nilai)).' '.$nama;

            return $depan.' '.self::ubah($n % $nilai);
        }

        return (string) $n;
    }
}
