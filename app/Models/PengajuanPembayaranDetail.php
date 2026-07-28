<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Baris pengajuan pembayaran. kode_unit PER BARIS (wajib). Tanpa timestamps. */
class PengajuanPembayaranDetail extends Model
{
    protected $table = 'pengajuan_pembayaran_detail';

    public $timestamps = false;

    protected $fillable = ['id_pengajuan', 'kode_coa', 'nama_coa', 'nominal', 'kode_unit', 'keterangan'];

    protected function casts(): array
    {
        return ['nominal' => 'decimal:2'];
    }

    public function pengajuan(): BelongsTo
    {
        return $this->belongsTo(PengajuanPembayaran::class, 'id_pengajuan', 'id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class, 'kode_unit', 'kode_unit');
    }
}
