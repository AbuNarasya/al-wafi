<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu barang dalam dokumen opname. `stok_tercatat` dipotret saat `stok_fisik` diisi —
 * penjualan sesudah barang itu dihitung tak ikut terbaca sebagai selisih.
 */
class OpnamePersediaanDetail extends Model
{
    protected $table = 'opname_persediaan_detail';

    public $timestamps = false;

    protected $fillable = [
        'id_opname', 'kode_persediaan', 'stok_fisik', 'stok_tercatat', 'dihitung_pada', 'nilai_selisih', 'id_mutasi',
    ];

    protected function casts(): array
    {
        return [
            'stok_fisik' => 'decimal:4', 'stok_tercatat' => 'decimal:4', 'dihitung_pada' => 'datetime',
            'nilai_selisih' => 'decimal:2',
        ];
    }

    public function persediaan(): BelongsTo
    {
        return $this->belongsTo(Inventory::class, 'kode_persediaan', 'kode_persediaan');
    }

    /** Fisik dikurangi tercatat; null bila belum dihitung. */
    public function selisih(): ?string
    {
        return $this->stok_fisik === null ? null : Money::sub($this->stok_fisik, $this->stok_tercatat ?? '0', 4);
    }
}
