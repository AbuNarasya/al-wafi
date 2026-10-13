<?php

namespace App\Services\PortalWali;

use App\Exceptions\AppException;

/**
 * Kolom pencatat sebuah transaksi: staf ATAU wali (tepat satu — CHECK di basis data).
 *
 * `$wali` = ['id' => id_wali, 'peran' => ayah|ibu|wali] untuk transaksi dari
 * Portal Wali; null untuk transaksi yang dicatat staf seperti biasa.
 */
final class Pencatat
{
    /**
     * @param  array{id:int, peran:string}|null  $wali
     * @return array{dicatat_oleh:?int, dicatat_oleh_wali:?int, dicatat_oleh_peran:?string}
     */
    public static function kolom(?int $idPengguna, ?array $wali): array
    {
        if ($wali !== null) {
            return ['dicatat_oleh' => null, 'dicatat_oleh_wali' => (int) $wali['id'], 'dicatat_oleh_peran' => (string) $wali['peran']];
        }
        if ($idPengguna === null) {
            throw new AppException(500, 'Transaksi tanpa pencatat tidak boleh disimpan.');
        }

        return ['dicatat_oleh' => $idPengguna, 'dicatat_oleh_wali' => null, 'dicatat_oleh_peran' => null];
    }
}
