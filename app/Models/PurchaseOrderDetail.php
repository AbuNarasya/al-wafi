<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Baris PO. Sisa = kuantiti - qty_invoiced. */
class PurchaseOrderDetail extends Model
{
    protected $table = 'purchase_order_details';

    public $timestamps = false;

    protected $fillable = [
        'id_po', 'kode_coa', 'nama_coa', 'keterangan',
        'kuantiti', 'harga_satuan', 'total', 'qty_invoiced', 'kode_persediaan',
    ];

    protected function casts(): array
    {
        return [
            'kuantiti' => 'decimal:4',
            'harga_satuan' => 'decimal:2',
            'total' => 'decimal:2',
            'qty_invoiced' => 'decimal:4',
        ];
    }

    public function po(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'id_po', 'id_po');
    }
}
