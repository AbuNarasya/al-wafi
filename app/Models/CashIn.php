<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Kas Masuk (Receivable Voucher). */
class CashIn extends Model
{
    protected $table = 'cash_in';

    protected $primaryKey = 'kode_transaksi';

    protected $fillable = [
        'nomor_transaksi', 'tanggal', 'kode_unit', 'kode_rekening', 'kode_customer',
        'referensi', 'keterangan', 'nominal', 'status',
        'void_reason', 'void_by', 'void_at', 'id_pengguna',
    ];

    protected function casts(): array
    {
        return ['tanggal' => 'date', 'nominal' => 'decimal:2', 'void_at' => 'datetime'];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class, 'kode_unit', 'kode_unit');
    }

    public function rekening(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'kode_rekening', 'kode_coa');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'kode_customer', 'kode_customer');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_pengguna', 'id_pengguna');
    }

    public function details(): HasMany
    {
        return $this->hasMany(CashInDetail::class, 'kode_transaksi', 'kode_transaksi');
    }
}
