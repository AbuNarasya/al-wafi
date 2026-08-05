<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Usulan anggaran scope (tahun+bagian+unit). status: diajukan|disetujui|ditolak|dibatalkan. */
class BudgetPengajuan extends Model
{
    protected $table = 'budget_pengajuan';

    protected $fillable = [
        'nomor', 'tahun', 'bulan_awal', 'kode_bagian', 'kode_unit',
        'status', 'nominal', 'keterangan', 'id_pengguna',
    ];

    protected function casts(): array
    {
        return [
            'tahun' => 'integer',
            'bulan_awal' => 'integer',
            'nominal' => 'decimal:2',
        ];
    }

    public function bagian(): BelongsTo
    {
        return $this->belongsTo(Bagian::class, 'kode_bagian', 'kode_bagian');
    }

    public function details(): HasMany
    {
        return $this->hasMany(BudgetPengajuanDetail::class, 'id_pengajuan', 'id');
    }

    /** Unit bisnis usulan — kosong berarti berlaku untuk semua unit. */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class, 'kode_unit', 'kode_unit');
    }
}
