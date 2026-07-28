<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Unit bisnis (dimensi 1 voucher = 1 unit pada level entry jurnal).
 */
class BusinessUnit extends Model
{
    protected $table = 'business_units';

    protected $primaryKey = 'kode_unit';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'kode_unit',
        'nama_unit',
        'status',
    ];

    public function unitDefaults(): HasMany
    {
        return $this->hasMany(UnitDefault::class, 'kode_unit', 'kode_unit');
    }
}
