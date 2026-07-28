<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Level otorisasi keuangan (batas nominal transaksi). PK natural: kode_level.
 */
class Level extends Model
{
    protected $table = 'levels';

    protected $primaryKey = 'kode_level';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'kode_level',
        'nama_level',
        'max_transaksi',
        'keterangan',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'max_transaksi' => 'decimal:2',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'kode_level', 'kode_level');
    }
}
