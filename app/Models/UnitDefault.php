<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Unit bisnis default per modul asal jurnal. PK: sumber_modul.
 */
class UnitDefault extends Model
{
    protected $table = 'unit_defaults';

    protected $primaryKey = 'sumber_modul';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'sumber_modul',
        'kode_unit',
        'keterangan',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class, 'kode_unit', 'kode_unit');
    }
}
