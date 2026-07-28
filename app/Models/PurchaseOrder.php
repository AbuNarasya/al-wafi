<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Purchase Order (tidak menghasilkan jurnal). */
class PurchaseOrder extends Model
{
    protected $table = 'purchase_orders';

    protected $primaryKey = 'id_po';

    protected $fillable = [
        'nomor_po', 'tanggal_po', 'kode_vendor', 'kode_unit',
        'keterangan', 'total_po', 'status', 'id_pengguna',
    ];

    protected function casts(): array
    {
        return ['tanggal_po' => 'date', 'total_po' => 'decimal:2'];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'kode_vendor', 'kode_vendor');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class, 'kode_unit', 'kode_unit');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_pengguna', 'id_pengguna');
    }

    public function details(): HasMany
    {
        return $this->hasMany(PurchaseOrderDetail::class, 'id_po', 'id_po');
    }
}
