<?php

namespace App\Services\PortalWali;

use App\Models\NotifikasiWali;
use App\Services\Modules\NotificationService;

/**
 * Kabar kepada PENCATAT sebuah transaksi — staf atau wali.
 *
 * Penolakan oleh keuangan dulu selalu dikirim ke `dicatat_oleh` (staf). Sejak
 * transaksi bisa dicatat wali lewat portal, kolom itu bisa kosong, dan tabel
 * notifikasi staf menolak id kosong — penolakannya sendiri ikut gagal. Inilah
 * satu pintu yang memilih penerimanya.
 */
class KabarWali
{
    /**
     * @param  object{dicatat_oleh:?int, dicatat_oleh_wali:?int, dicatat_oleh_peran:?string}  $transaksi
     * @param  array{judul:string, pesan:string, jenis:string, ref_jenis?:?string, ref_id?:?string, id_santri?:?int}  $isi
     */
    public static function kePencatat(object $transaksi, array $isi): void
    {
        if ($transaksi->dicatat_oleh_wali !== null) {
            NotifikasiWali::create([
                'id_wali' => $transaksi->dicatat_oleh_wali, 'peran' => $transaksi->dicatat_oleh_peran,
                'id_santri' => $isi['id_santri'] ?? null, 'judul' => $isi['judul'], 'pesan' => $isi['pesan'],
                'jenis' => $isi['jenis'], 'ref_jenis' => $isi['ref_jenis'] ?? null, 'ref_id' => $isi['ref_id'] ?? null,
            ]);

            return;
        }
        if ($transaksi->dicatat_oleh !== null) {
            (new NotificationService)->kirim([[
                'id_pengguna' => $transaksi->dicatat_oleh, 'judul' => $isi['judul'], 'pesan' => $isi['pesan'],
                'jenis' => $isi['jenis'], 'ref_jenis' => $isi['ref_jenis'] ?? null, 'ref_id' => $isi['ref_id'] ?? null,
            ]]);
        }
    }
}
