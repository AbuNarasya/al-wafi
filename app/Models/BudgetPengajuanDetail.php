<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Baris usulan anggaran per akun per bulan. */
class BudgetPengajuanDetail extends Model
{
    protected $table = 'budget_pengajuan_detail';

    public $timestamps = false;

    protected $fillable = ['id_pengajuan', 'kode_coa', 'nama_coa', 'bulan', 'nominal'];

    protected function casts(): array
    {
        return ['bulan' => 'integer', 'nominal' => 'decimal:2'];
    }

    public function pengajuan(): BelongsTo
    {
        return $this->belongsTo(BudgetPengajuan::class, 'id_pengajuan', 'id');
    }
}
