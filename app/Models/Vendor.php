<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Master vendor. */
class Vendor extends Model
{
    protected $table = 'vendors';

    protected $primaryKey = 'kode_vendor';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'kode_vendor',
        'nama_vendor',
        'kode_jenis_vendor',
        'alamat',
        'telepon',
        'metode_pembayaran',
        'termin_hari',
        'no_rekening',
        'bank',
        'atas_nama',
        'status',
    ];

    protected function casts(): array
    {
        return ['termin_hari' => 'integer'];
    }

    public function jenis(): BelongsTo
    {
        return $this->belongsTo(VendorType::class, 'kode_jenis_vendor', 'kode_jenis_vendor');
    }
}
