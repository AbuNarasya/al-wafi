<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** PIN transaksi dompet milik SATU orang (keluarga + peran) — lihat App\Services\PortalWali\PinWali. */
class PinWali extends Model
{
    protected $table = 'pin_wali';

    protected $guarded = ['id'];

    protected $hidden = ['pin_hash'];

    protected function casts(): array
    {
        return ['terkunci_sampai' => 'datetime', 'diubah_pada' => 'datetime', 'gagal' => 'integer'];
    }
}
