<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Saldo awal per akun. posted=true → sudah jadi jurnal pembuka (terkunci).
 */
class OpeningBalance extends Model
{
    protected $table = 'opening_balances';

    protected $fillable = [
        'kode_coa',
        'jenis_saldo',
        'saldo',
        'posted',
        'journal_entry_id',
    ];

    protected function casts(): array
    {
        return [
            'saldo' => 'decimal:2',
            'posted' => 'boolean',
        ];
    }

    public function coa(): BelongsTo
    {
        return $this->belongsTo(CoaDetail::class, 'kode_coa', 'kode_coa');
    }
}
