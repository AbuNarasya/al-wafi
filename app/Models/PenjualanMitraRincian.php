<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PenjualanMitraRincian extends Model
{
    public $timestamps = false;

    protected $table = 'penjualan_mitra_rincian';

    protected $fillable = ['id_penjualan_mitra', 'id_menu', 'nama', 'harga', 'jumlah', 'subtotal'];

    protected function casts(): array
    {
        return ['harga' => 'decimal:2', 'subtotal' => 'decimal:2'];
    }
}
