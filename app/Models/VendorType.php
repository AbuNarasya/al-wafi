<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Master jenis vendor. */
class VendorType extends Model
{
    protected $table = 'vendor_types';

    protected $primaryKey = 'kode_jenis_vendor';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['kode_jenis_vendor', 'nama', 'status'];

    public function vendors(): HasMany
    {
        return $this->hasMany(Vendor::class, 'kode_jenis_vendor', 'kode_jenis_vendor');
    }
}
