<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Baris rekonsiliasi bank. cleared = muncul di koran. */
class BankReconciliationItem extends Model
{
    protected $table = 'bank_reconciliation_items';

    public $timestamps = false;

    protected $fillable = [
        'id_rekonsiliasi', 'journal_line_id', 'entry_id', 'tanggal',
        'keterangan', 'debet', 'kredit', 'cleared', 'is_adjustment',
    ];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'debet' => 'decimal:2',
            'kredit' => 'decimal:2',
            'cleared' => 'boolean',
            'is_adjustment' => 'boolean',
        ];
    }

    public function rekonsiliasi(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class, 'id_rekonsiliasi', 'id');
    }
}
