<?php

namespace App\Services\Penghubung;

use App\Exceptions\AppException;

/**
 * Jawaban penghubung TAK PASTI: waktu habis, koneksi putus, atau ERP Al Wafi galat 5xx. Permintaannya bisa saja
 * SUDAH diproses di sana — karena itu kasir tidak boleh menganggapnya gagal sebelum nomornya dipastikan
 * (`KlienDompetToko::pastikan`). Berbeda dengan penolakan (saldo, PIN, kartu) yang pasti tak memotong apa pun.
 */
class PenghubungTakPasti extends AppException
{
    public function __construct(string $message = KlienDompetToko::TIDAK_TERSEDIA)
    {
        parent::__construct(503, $message);
    }
}
