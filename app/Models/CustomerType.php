<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Master jenis customer. */
class CustomerType extends Model
{
    protected $table = 'customer_types';

    protected $primaryKey = 'kode_jenis_customer';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['kode_jenis_customer', 'nama', 'status'];

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class, 'kode_jenis_customer', 'kode_jenis_customer');
    }
}
