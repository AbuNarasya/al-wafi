<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pengajuan Pembayaran (§4). jenis: pembayaran | uang_muka | penyelesaian_uang_muka.
 * Unit melekat di baris. kode_coa_hutang ditetapkan keuangan saat verifikasi.
 */
class PengajuanPembayaran extends Model
{
    protected $table = 'pengajuan_pembayaran';

    protected $fillable = [
        'nomor', 'tanggal', 'jenis', 'kode_bagian', 'kode_coa_hutang',
        'nominal', 'sisa_hutang', 'keterangan', 'referensi', 'status',
        'id_uang_muka', 'kode_rekening', 'void_reason', 'void_by', 'void_at',
        'journal_entry_id', 'id_pengguna',
    ];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'nominal' => 'decimal:2',
            'sisa_hutang' => 'decimal:2',
            'void_at' => 'datetime',
        ];
    }

    public function bagian(): BelongsTo
    {
        return $this->belongsTo(Bagian::class, 'kode_bagian', 'kode_bagian');
    }

    public function details(): HasMany
    {
        return $this->hasMany(PengajuanPembayaranDetail::class, 'id_pengajuan', 'id');
    }

    public function pemohon(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_pengguna', 'id_pengguna');
    }
}
