<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Invoice vendor (pengakuan hutang). status: belum_bayar | sebagian | lunas. */
class Invoice extends Model
{
    protected $table = 'invoices';

    protected $primaryKey = 'id_invoice';

    protected $fillable = [
        'nomor_invoice', 'nomor_ref_internal', 'tanggal_invoice', 'tanggal_jatuh_tempo',
        'kode_vendor', 'kode_unit', 'kode_coa_hutang', 'id_po', 'nomor_po',
        'keterangan', 'total', 'sisa_hutang', 'status', 'id_pengguna',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_invoice' => 'date',
            'tanggal_jatuh_tempo' => 'date',
            'total' => 'decimal:2',
            'sisa_hutang' => 'decimal:2',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'kode_vendor', 'kode_vendor');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class, 'kode_unit', 'kode_unit');
    }

    public function po(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'id_po', 'id_po');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_pengguna', 'id_pengguna');
    }

    public function details(): HasMany
    {
        return $this->hasMany(InvoiceDetail::class, 'id_invoice', 'id_invoice');
    }
}
