<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jurnal akrual/penyesuaian. status: aktif | reversed.
 * kode_coa_debet/kredit tanpa FK (validasi di service).
 */
class Accrue extends Model
{
    protected $table = 'accrues';

    protected $primaryKey = 'id_accrue';

    protected $fillable = [
        'tanggal',
        'periode',
        'kode_coa_debet',
        'nama_coa_debet',
        'kode_coa_kredit',
        'nama_coa_kredit',
        'nominal',
        'kode_unit',
        'nomor_referensi',
        'keterangan',
        'status',
        'id_pengguna',
    ];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'nominal' => 'decimal:2',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class, 'kode_unit', 'kode_unit');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_pengguna', 'id_pengguna');
    }
}
