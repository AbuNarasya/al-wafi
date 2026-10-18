<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris barang yang diterima. `kuantiti` & `total` dalam satuan DASAR; `satuan`,
 * `isi`, `kuantiti_input`, `harga_input` merekam apa yang dihitung petugas (mis. 3 DUS).
 */
class PenerimaanBarangDetail extends Model
{
    protected $table = 'penerimaan_barang_detail';

    public $timestamps = false;

    protected $fillable = [
        'id_penerimaan', 'id_po_detail', 'kode_persediaan', 'satuan', 'isi', 'kuantiti_input',
        'harga_input', 'kuantiti', 'total', 'qty_ditagih', 'nilai_ditagih', 'keterangan',
    ];

    protected function casts(): array
    {
        return [
            'isi' => 'decimal:4', 'kuantiti_input' => 'decimal:4', 'harga_input' => 'decimal:2',
            'kuantiti' => 'decimal:4', 'total' => 'decimal:2',
            'qty_ditagih' => 'decimal:4', 'nilai_ditagih' => 'decimal:2',
        ];
    }

    public function penerimaan(): BelongsTo
    {
        return $this->belongsTo(PenerimaanBarang::class, 'id_penerimaan', 'id_penerimaan');
    }

    public function persediaan(): BelongsTo
    {
        return $this->belongsTo(Inventory::class, 'kode_persediaan', 'kode_persediaan');
    }

    public function sisaQty(): string
    {
        return Money::sub($this->kuantiti, $this->qty_ditagih, 4);
    }

    public function sisaNilai(): string
    {
        return Money::sub($this->total, $this->nilai_ditagih);
    }
}
