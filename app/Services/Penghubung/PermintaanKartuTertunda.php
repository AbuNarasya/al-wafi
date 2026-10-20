<?php

namespace App\Services\Penghubung;

use App\Exceptions\AppException;

/**
 * Pembayaran kartu yang nasibnya BELUM selesai di kasir (Fase 3c-1): status di ERP Al Wafi belum bisa dipastikan,
 * atau kartu sudah terpotong tetapi struknya gagal disimpan. Layar kasir menahan keranjangnya dan menawarkan
 * "Cek lagi" — kasir TIDAK boleh menerima tunai selama ini.
 */
class PermintaanKartuTertunda extends AppException
{
    public function __construct(public readonly int $idPermintaan, string $message)
    {
        parent::__construct(409, $message);
    }
}
