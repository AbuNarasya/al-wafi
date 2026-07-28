<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Akun detail (level 4). jenis_saldo (debet/kredit) = sisi normal akun.
 */
class CoaDetail extends Model
{
    protected $table = 'coa_detail';

    protected $primaryKey = 'kode_coa';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'kode_coa',
        'nama_coa',
        'kode_grup',
        'jenis_saldo',
        'status',
        'keterangan',
    ];

    public function grup(): BelongsTo
    {
        return $this->belongsTo(CoaGroup::class, 'kode_grup', 'kode_grup');
    }

    public function bankAccount(): HasOne
    {
        return $this->hasOne(BankAccount::class, 'kode_coa', 'kode_coa');
    }
}
