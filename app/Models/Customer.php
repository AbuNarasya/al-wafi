<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Master customer. */
class Customer extends Model
{
    protected $table = 'customers';

    protected $primaryKey = 'kode_customer';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'kode_customer',
        'nama_customer',
        'kode_jenis_customer',
        'kode_coa_pendapatan',
        'kode_coa_piutang',
        'alamat',
        'telepon',
        'email',
        'status',
    ];

    public function jenis(): BelongsTo
    {
        return $this->belongsTo(CustomerType::class, 'kode_jenis_customer', 'kode_jenis_customer');
    }
}
