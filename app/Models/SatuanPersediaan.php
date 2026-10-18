<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satuan konversi sebuah barang: 1 `satuan` = `isi` satuan DASAR (`inventory.satuan`).
 * Stok selalu dicatat dalam satuan dasar; ini hanya cara menyebut jumlah.
 */
class SatuanPersediaan extends Model
{
    protected $table = 'satuan_persediaan';

    protected $fillable = ['kode_persediaan', 'satuan', 'isi', 'harga_jual'];

    protected function casts(): array
    {
        return ['isi' => 'decimal:4', 'harga_jual' => 'decimal:2'];
    }
}
