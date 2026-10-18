<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Retur penjualan — lihat [[App\Services\Modules\KasirService::retur()]]. */
class ReturPenjualan extends Model
{
    public const SUMBER = 'ReturPenjualan';

    protected $table = 'retur_penjualan';

    protected $primaryKey = 'id_retur';

    protected $fillable = [
        'nomor', 'id_penjualan', 'id_shift', 'kode_lokasi', 'waktu', 'total', 'hpp_kembali',
        'alasan', 'id_kasir', 'disetujui_oleh',
    ];

    protected function casts(): array
    {
        return ['waktu' => 'datetime', 'total' => 'decimal:2', 'hpp_kembali' => 'decimal:2'];
    }

    public function details(): HasMany
    {
        return $this->hasMany(ReturPenjualanDetail::class, 'id_retur', 'id_retur')->orderBy('id');
    }

    public function penjualan(): BelongsTo
    {
        return $this->belongsTo(Penjualan::class, 'id_penjualan', 'id_penjualan');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(ShiftKasir::class, 'id_shift', 'id_shift');
    }

    public function kasir(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_kasir', 'id_pengguna');
    }

    public function penyetuju(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disetujui_oleh', 'id_pengguna');
    }
}
