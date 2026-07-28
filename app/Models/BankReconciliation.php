<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Rekonsiliasi bank (buku vs rekening koran). status: draft | selesai. */
class BankReconciliation extends Model
{
    protected $table = 'bank_reconciliations';

    protected $fillable = [
        'kode_coa', 'tanggal', 'saldo_bank', 'saldo_buku',
        'status', 'keterangan', 'id_pengguna',
    ];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'saldo_bank' => 'decimal:2',
            'saldo_buku' => 'decimal:2',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(BankReconciliationItem::class, 'id_rekonsiliasi', 'id');
    }
}
