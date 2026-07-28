<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Master persediaan. Stok saat ini = stok_masuk - stok_keluar. */
class Inventory extends Model
{
    protected $table = 'inventory';

    protected $primaryKey = 'kode_persediaan';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'kode_persediaan',
        'nama_persediaan',
        'satuan',
        'harga_perolehan',
        'stok_masuk',
        'stok_keluar',
        'kode_coa',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'harga_perolehan' => 'decimal:2',
            'stok_masuk' => 'decimal:4',
            'stok_keluar' => 'decimal:4',
        ];
    }
}
