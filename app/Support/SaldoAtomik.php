<?php

namespace App\Support;

use App\Exceptions\AppException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * AUDIT 22 Okt 2026 — saldo dompet/tabungan/prabayar dulu diubah dengan pola baca-hitung-tulis
 * (`$d->update(['saldo' => saldo - x])`). Dua transaksi serentak membaca saldo yang sama, yang
 * terakhir menimpa yang pertama → satu potongan hilang (uang "tercipta") atau saldo jadi minus.
 *
 * Kini satu pintu: `UPDATE … SET saldo = saldo + Δ … RETURNING saldo` — atomik di database,
 * dan pengurangan ditolak bila saldo tak cukup (tanpa galat SQL, sehingga transaksi pemanggil
 * tetap utuh). CHECK `saldo >= 0` (migrasi 000095) jadi penjaga terakhirnya.
 */
final class SaldoAtomik
{
    /** @return string saldo sesudahnya */
    public static function tambah(Model $model, string $nominal): string
    {
        return self::geser($model, Money::of($nominal), 'Saldo');
    }

    /** @return string saldo sesudahnya */
    public static function kurangi(Model $model, string $nominal, string $label = 'Saldo'): string
    {
        return self::geser($model, Money::sub('0', $nominal), $label);
    }

    private static function geser(Model $model, string $delta, string $label): string
    {
        $tabel = $model->getTable();
        $kunci = $model->getKeyName();
        $baris = DB::selectOne(
            "UPDATE {$tabel} SET saldo = saldo + CAST(? AS numeric), updated_at = ? "
            ."WHERE {$kunci} = ? AND saldo + CAST(? AS numeric) >= 0 RETURNING saldo",
            [$delta, now(), $model->getKey(), $delta],
        );
        if (! $baris) {
            $tersedia = DB::table($tabel)->where($kunci, $model->getKey())->value('saldo');
            if ($tersedia === null) {
                throw new AppException(404, __(':label tidak ditemukan.', ['label' => __($label)]));
            }
            throw new AppException(422, __(':label tidak cukup (tersedia :saldo).', ['label' => __($label), 'saldo' => Money::of($tersedia)]));
        }
        $model->forceFill(['saldo' => $baris->saldo])->syncOriginalAttribute('saldo');

        return Money::of($baris->saldo);
    }
}
