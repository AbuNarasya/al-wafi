<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Aset tetap. metode_depresiasi: garis_lurus | saldo_menurun. */
class Asset extends Model
{
    protected $table = 'assets';

    protected $primaryKey = 'kode_aset';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'kode_aset',
        'nama_aset',
        'kategori_aset',
        'kuantiti',
        'harga_perolehan',
        'tanggal_perolehan',
        'umur_manfaat',
        'metode_depresiasi',
        'nilai_residu',
        'akumulasi_depresiasi',
        'kode_coa',
        'status',
        'sumber_ref',
    ];

    protected function casts(): array
    {
        return [
            'kuantiti' => 'decimal:4',
            'harga_perolehan' => 'decimal:2',
            'tanggal_perolehan' => 'date',
            'umur_manfaat' => 'integer',
            'nilai_residu' => 'decimal:2',
            'akumulasi_depresiasi' => 'decimal:2',
        ];
    }

    public function movements(): HasMany
    {
        return $this->hasMany(AssetMovement::class, 'kode_aset', 'kode_aset');
    }
}
