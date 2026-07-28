<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Penyelesaian Uang Muka (bisa memicu multi-entry jurnal). */
class AdvanceSettlement extends Model
{
    protected $table = 'advance_settlements';

    protected $fillable = [
        'tanggal', 'kode_coa_uang_muka', 'nama_coa_uang_muka', 'nominal_uang_muka',
        'kode_coa_realisasi', 'nama_coa_realisasi', 'nominal_realisasi', 'kode_rekening',
        'kode_unit', 'nomor_referensi', 'id_uang_muka', 'keterangan', 'status', 'id_pengguna',
    ];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'nominal_uang_muka' => 'decimal:2',
            'nominal_realisasi' => 'decimal:2',
        ];
    }

    public function rekening(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'kode_rekening', 'kode_coa');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_pengguna', 'id_pengguna');
    }
}
