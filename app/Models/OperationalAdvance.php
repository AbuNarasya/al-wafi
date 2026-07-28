<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/** Uang Muka Belanja Operasional. Sisa = nominal - nominal_diselesaikan. */
class OperationalAdvance extends Model
{
    protected $table = 'operational_advances';

    protected $appends = ['sisa'];

    protected $fillable = [
        'nomor_ref', 'tanggal', 'kode_unit', 'kode_rekening', 'kode_coa_uang_muka',
        'nama_coa_uang_muka', 'penerima', 'keterangan', 'nominal', 'nominal_diselesaikan',
        'status', 'void_reason', 'void_by', 'void_at', 'id_pengguna', 'id_pengajuan_sumber',
    ];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'nominal' => 'decimal:2',
            'nominal_diselesaikan' => 'decimal:2',
            'void_at' => 'datetime',
        ];
    }

    /** Sisa outstanding = nominal - nominal_diselesaikan. */
    protected function sisa(): Attribute
    {
        return Attribute::make(
            get: fn () => Money::sub($this->nominal ?? 0, $this->nominal_diselesaikan ?? 0),
        );
    }
}
