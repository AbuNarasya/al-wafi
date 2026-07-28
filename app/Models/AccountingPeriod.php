<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Periode akuntansi (tutup buku). status: open | closed. Unik per (tahun, bulan).
 */
class AccountingPeriod extends Model
{
    protected $table = 'accounting_periods';

    protected $fillable = [
        'tahun',
        'bulan',
        'status',
        'closed_by',
        'nama_closed_by',
        'closed_at',
        'reopened_at',
        'keterangan',
    ];

    protected function casts(): array
    {
        return [
            'tahun' => 'integer',
            'bulan' => 'integer',
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }
}
