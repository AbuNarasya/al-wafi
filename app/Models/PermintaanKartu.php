<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * NIAT BAYAR KARTU di instans Wafi Mart — disimpan SEBELUM memanggil penghubung (Fase 3c-1), supaya permintaan yang
 * jawabannya hilang selalu tercatat & bisa dipastikan. Lihat migrasi `pencegahan_potongan_yatim`.
 */
class PermintaanKartu extends Model
{
    protected $table = 'permintaan_kartu';

    protected $fillable = [
        'nomor', 'jenis', 'nominal', 'uid_kartu', 'nomor_asal', 'id_shift', 'id_kasir', 'status', 'hasil', 'pesan', 'id_penjualan', 'kunci_klien',
    ];

    protected function casts(): array
    {
        return ['nominal' => 'decimal:2', 'hasil' => 'array'];
    }
}
