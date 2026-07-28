<?php

namespace App\Services\Ledger;

use App\Exceptions\AppException;
use App\Models\Level;
use App\Models\User;
use App\Support\Money;

/**
 * Batas otorisasi nominal per level pengguna. max_transaksi null = tak terbatas.
 * Dipakai untuk void & aksi lain yang dibatasi per level.
 */
final class Authorization
{
    public static function checkAuthorization(string|int|float $nominal, string $kodeLevel): void
    {
        $level = Level::find($kodeLevel);
        if (! $level) {
            throw new AppException(400, 'Level otorisasi pengguna tidak ditemukan. Hubungi administrator.');
        }
        if ($level->max_transaksi === null) {
            return; // tidak terbatas
        }
        if (Money::gt($nominal, $level->max_transaksi)) {
            throw new AppException(
                403,
                'Nominal '.Money::of($nominal)." melebihi batas otorisasi level {$level->nama_level} (maks. "
                .Money::of($level->max_transaksi).'). Minta approval ke level lebih tinggi.'
            );
        }
    }

    public static function authorizeByUser(?int $idPengguna, string|int|float $nominal): void
    {
        if (! $idPengguna) {
            return;
        }
        $user = User::find($idPengguna);
        if ($user) {
            self::checkAuthorization($nominal, $user->kode_level);
        }
    }

    /** Versi boolean (tanpa melempar). */
    public static function canAuthorize(?int $idPengguna, string|int|float $nominal): bool
    {
        if (! $idPengguna) {
            return true;
        }
        $user = User::find($idPengguna);
        if (! $user) {
            return true;
        }
        $level = Level::find($user->kode_level);
        if (! $level || $level->max_transaksi === null) {
            return true;
        }

        return Money::lte($nominal, $level->max_transaksi);
    }
}
