<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Anggaran per akun/bulan/unit. Realisasi dihitung dari journal_lines. */
class Budget extends Model
{
    protected $table = 'budgets';

    protected $fillable = [
        'tahun', 'bulan', 'kode_coa', 'kode_bagian', 'kode_unit', 'nominal', 'keterangan',
    ];

    protected function casts(): array
    {
        return [
            'tahun' => 'integer',
            'bulan' => 'integer',
            'nominal' => 'decimal:2',
        ];
    }

    public function bagian(): BelongsTo
    {
        return $this->belongsTo(Bagian::class, 'kode_bagian', 'kode_bagian');
    }
}
