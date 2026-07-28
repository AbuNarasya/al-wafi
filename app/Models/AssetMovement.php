<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Penambahan nilai perolehan aset dari transaksi. Hanya created_at. */
class AssetMovement extends Model
{
    protected $table = 'asset_movements';

    public const UPDATED_AT = null;

    protected $fillable = [
        'kode_aset',
        'sumber_ref',
        'sumber_modul',
        'nominal',
        'kuantiti',
    ];

    protected function casts(): array
    {
        return [
            'nominal' => 'decimal:2',
            'kuantiti' => 'decimal:4',
        ];
    }

    public function aset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'kode_aset', 'kode_aset');
    }
}
