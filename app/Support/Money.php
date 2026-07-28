<?php

namespace App\Support;

/**
 * Aritmetika uang presisi tetap berbasis BCMath (string).
 * JANGAN gunakan float untuk uang — selalu lewat helper ini.
 * Uang default scale 2 (Decimal 18,2); kuantiti pakai scale 4.
 */
final class Money
{
    public const SCALE = 2;

    /** Normalisasi nilai ke string dengan scale tertentu. */
    public static function of(string|int|float|null $v, int $scale = self::SCALE): string
    {
        if ($v === null || $v === '') {
            $v = '0';
        }
        if (is_float($v)) {
            // Hindari notasi ilmiah; beri presisi ekstra sebelum dibulatkan bcmath.
            $v = sprintf('%.'.($scale + 6).'F', $v);
        }
        return bcadd((string) $v, '0', $scale);
    }

    public static function add(string|int|float|null $a, string|int|float|null $b, int $scale = self::SCALE): string
    {
        return bcadd(self::of($a, $scale), self::of($b, $scale), $scale);
    }

    public static function sub(string|int|float|null $a, string|int|float|null $b, int $scale = self::SCALE): string
    {
        return bcsub(self::of($a, $scale), self::of($b, $scale), $scale);
    }

    public static function mul(string|int|float|null $a, string|int|float|null $b, int $scale = self::SCALE): string
    {
        return bcmul(self::of($a, $scale), self::of($b, $scale), $scale);
    }

    /** Pembagian; melempar bila pembagi nol. */
    public static function div(string|int|float|null $a, string|int|float|null $b, int $scale = self::SCALE): string
    {
        if (self::isZero($b, $scale)) {
            throw new \DivisionByZeroError('Money::div pembagi tidak boleh nol.');
        }

        return bcdiv(self::of($a, $scale), self::of($b, $scale), $scale);
    }

    public static function gt(string|int|float|null $a, string|int|float|null $b, int $scale = self::SCALE): bool
    {
        return self::cmp($a, $b, $scale) > 0;
    }

    public static function gte(string|int|float|null $a, string|int|float|null $b, int $scale = self::SCALE): bool
    {
        return self::cmp($a, $b, $scale) >= 0;
    }

    public static function lt(string|int|float|null $a, string|int|float|null $b, int $scale = self::SCALE): bool
    {
        return self::cmp($a, $b, $scale) < 0;
    }

    public static function lte(string|int|float|null $a, string|int|float|null $b, int $scale = self::SCALE): bool
    {
        return self::cmp($a, $b, $scale) <= 0;
    }

    public static function cmp(string|int|float|null $a, string|int|float|null $b, int $scale = self::SCALE): int
    {
        return bccomp(self::of($a, $scale), self::of($b, $scale), $scale);
    }

    public static function isNegative(string|int|float|null $v, int $scale = self::SCALE): bool
    {
        return self::cmp($v, '0', $scale) < 0;
    }

    public static function isZero(string|int|float|null $v, int $scale = self::SCALE): bool
    {
        return self::cmp($v, '0', $scale) === 0;
    }

    public static function gtZero(string|int|float|null $v, int $scale = self::SCALE): bool
    {
        return self::cmp($v, '0', $scale) > 0;
    }

    public static function eq(string|int|float|null $a, string|int|float|null $b, int $scale = self::SCALE): bool
    {
        return self::cmp($a, $b, $scale) === 0;
    }
}
