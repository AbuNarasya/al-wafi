<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Kas Keluar (Payment Voucher). */
class CashOut extends Model
{
    protected $table = 'cash_out';

    protected $primaryKey = 'kode_transaksi';

    protected $fillable = [
        'nomor_transaksi', 'tanggal', 'kode_unit', 'kode_rekening', 'kode_vendor',
        'referensi', 'keterangan', 'nominal', 'id_bank_loan', 'status',
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

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'kode_vendor', 'kode_vendor');
    }

    public function bankLoan(): BelongsTo
    {
        return $this->belongsTo(BankLoan::class, 'id_bank_loan', 'id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_pengguna', 'id_pengguna');
    }

    public function details(): HasMany
    {
        return $this->hasMany(CashOutDetail::class, 'kode_transaksi', 'kode_transaksi');
    }
}
