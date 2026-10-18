<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu baris struk. `kuantiti` dalam satuan DASAR; `hpp` = harga pokok FIFO baris ini. */
class PenjualanDetail extends Model
{
    protected $table = 'penjualan_detail';

    public $timestamps = false;

    protected $fillable = [
        'id_penjualan', 'kode_persediaan', 'barcode', 'satuan', 'isi', 'kuantiti_input', 'kuantiti',
        'harga_satuan', 'total', 'hpp', 'harga_normal', 'promo', 'diskon',
    ];

    protected function casts(): array
    {
        return [
            'isi' => 'decimal:4', 'kuantiti_input' => 'decimal:4', 'kuantiti' => 'decimal:4',
            'harga_satuan' => 'decimal:2', 'total' => 'decimal:2', 'hpp' => 'decimal:2',
            'harga_normal' => 'decimal:2', 'promo' => 'boolean', 'diskon' => 'decimal:2',
        ];
    }

    public function persediaan(): BelongsTo
    {
        return $this->belongsTo(Inventory::class, 'kode_persediaan', 'kode_persediaan');
    }
}
