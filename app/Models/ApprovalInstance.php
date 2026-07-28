<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Satu dokumen yang sedang/sudah melewati rantai. 1 dokumen = 1 instance. */
class ApprovalInstance extends Model
{
    protected $table = 'approval_instances';

    protected $fillable = [
        'kode_flow', 'jenis_dokumen', 'id_dokumen', 'kode_bagian', 'kode_coa',
        'tahun', 'bulan', 'nominal', 'status', 'tahap_sekarang',
        'overbudget', 'belum_dianggarkan', 'id_pemohon', 'posted',
    ];

    protected function casts(): array
    {
        return [
            'tahun' => 'integer',
            'bulan' => 'integer',
            'nominal' => 'decimal:2',
            'tahap_sekarang' => 'integer',
            'overbudget' => 'boolean',
            'belum_dianggarkan' => 'boolean',
            'posted' => 'boolean',
        ];
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ApprovalLog::class, 'id_instance', 'id');
    }
}
