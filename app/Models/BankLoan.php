<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Master pembiayaan/pinjaman bank. Sisa pokok = pokok_awal - pokok_terbayar. */
class BankLoan extends Model
{
    protected $table = 'bank_loans';

    protected $appends = ['sisa_pokok'];

    protected $fillable = [
        'nama_bank', 'nomor_kontrak', 'jenis_akad', 'pokok_awal', 'margin',
        'tenor_bulan', 'tanggal_mulai', 'tanggal_jatuh_tempo', 'kode_coa_hutang',
        'kode_coa_beban_bunga', 'kode_rekening', 'pokok_terbayar', 'status',
        'keterangan', 'void_reason', 'void_by', 'void_at', 'id_pengguna',
    ];

    protected function casts(): array
    {
        return [
            'pokok_awal' => 'decimal:2',
            'margin' => 'decimal:2',
            'tenor_bulan' => 'integer',
            'tanggal_mulai' => 'date',
            'tanggal_jatuh_tempo' => 'date',
            'pokok_terbayar' => 'decimal:2',
            'void_at' => 'datetime',
        ];
    }

    /** Sisa pokok = pokok_awal - pokok_terbayar (computed). */
    protected function sisaPokok(): Attribute
    {
        return Attribute::make(
            get: fn () => Money::sub($this->pokok_awal ?? 0, $this->pokok_terbayar ?? 0),
        );
    }

    public function cashOuts(): HasMany
    {
        return $this->hasMany(CashOut::class, 'id_bank_loan', 'id');
    }
}
