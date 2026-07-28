<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Baris invoice. */
class InvoiceDetail extends Model
{
    protected $table = 'invoice_details';

    public $timestamps = false;

    protected $fillable = [
        'id_invoice', 'kode_coa', 'nama_coa', 'keterangan',
        'kuantiti', 'harga_satuan', 'total', 'kode_persediaan',
    ];

    protected function casts(): array
    {
        return [
            'kuantiti' => 'decimal:4',
            'harga_satuan' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'id_invoice', 'id_invoice');
    }
}
