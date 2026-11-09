<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pengajuan penarikan saldo mitra kantin — lihat [[App\Services\Modules\SaldoMitraService]]. */
class PenarikanMitra extends Model
{
    protected $table = 'penarikan_mitra';

    protected $fillable = [
        'nomor', 'id_mitra', 'nominal', 'biaya_transfer', 'status', 'catatan_mitra', 'bank_nama', 'bank_rekening', 'bank_atas_nama',
        'diajukan_oleh', 'diproses_oleh', 'diproses_pada', 'tanggal_transfer', 'kode_coa_bank', 'bukti_path', 'bukti_nama',
        'alasan_tolak', 'journal_entry_id',
    ];

    protected function casts(): array
    {
        return ['nominal' => 'decimal:2', 'biaya_transfer' => 'decimal:2', 'diproses_pada' => 'datetime', 'tanggal_transfer' => 'date'];
    }

    public function mitra(): BelongsTo
    {
        return $this->belongsTo(Mitra::class, 'id_mitra');
    }

    public function pemroses(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diproses_oleh', 'id_pengguna');
    }

    /** Yang diterima mitra = nominal − biaya transfer. */
    public function diterima(): string
    {
        return \App\Support\Money::sub($this->nominal, $this->biaya_transfer ?? 0);
    }

    public const LABEL = ['diajukan' => 'Menunggu diproses', 'selesai' => 'Sudah ditransfer', 'ditolak' => 'Ditolak', 'dibatalkan' => 'Dibatalkan'];
}
