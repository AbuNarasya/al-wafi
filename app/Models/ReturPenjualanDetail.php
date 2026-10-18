<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu barang yang dikembalikan. `nilai` = uang kembali; `hpp` = bagian harga pokok baris asal
 * untuk jumlah ini (dicatat untuk KEDUA kondisi supaya retur berikutnya membagi sisa dengan
 * benar). Yang benar-benar kembali ke stok hanya baris `layak` — jumlahnya di
 * `retur_penjualan.hpp_kembali`.
 */
class ReturPenjualanDetail extends Model
{
    protected $table = 'retur_penjualan_detail';

    public $timestamps = false;

    protected $fillable = [
        'id_retur', 'id_penjualan_detail', 'kode_persediaan', 'kuantiti_input', 'kuantiti', 'kondisi', 'nilai', 'hpp',
    ];

    protected function casts(): array
    {
        return ['kuantiti_input' => 'decimal:4', 'kuantiti' => 'decimal:4', 'nilai' => 'decimal:2', 'hpp' => 'decimal:2'];
    }

    public function persediaan(): BelongsTo
    {
        return $this->belongsTo(Inventory::class, 'kode_persediaan', 'kode_persediaan');
    }

    public function baris(): BelongsTo
    {
        return $this->belongsTo(PenjualanDetail::class, 'id_penjualan_detail', 'id');
    }
}
