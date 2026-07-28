<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BARIS jurnal. debet/kredit Decimal(18,2), kuantiti Decimal(18,4). Tanpa
 * timestamps. Dimensi kode_bagian & kode_unit melekat di baris.
 */
class JournalLine extends Model
{
    protected $table = 'journal_lines';

    public $timestamps = false;

    protected $fillable = [
        'entry_id',
        'kode_coa',
        'nama_coa',
        'debet',
        'kredit',
        'keterangan',
        'kode_persediaan',
        'kuantiti',
        'kode_bagian',
        'kode_unit',
    ];

    protected function casts(): array
    {
        return [
            'debet' => 'decimal:2',
            'kredit' => 'decimal:2',
            'kuantiti' => 'decimal:4',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'entry_id', 'id');
    }

    public function coa(): BelongsTo
    {
        return $this->belongsTo(CoaDetail::class, 'kode_coa', 'kode_coa');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class, 'kode_unit', 'kode_unit');
    }

    public function bagian(): BelongsTo
    {
        return $this->belongsTo(Bagian::class, 'kode_bagian', 'kode_bagian');
    }
}
