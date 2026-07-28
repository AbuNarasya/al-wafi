<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu setoran santri. Dicatat PPSB, diverifikasi Keuangan (pemisahan dua tangan). */
class PembayaranSantri extends Model
{
    protected $table = 'pembayaran_santri';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'nominal' => 'decimal:2',
            'diverifikasi_pada' => 'datetime',
        ];
    }

    public function santri(): BelongsTo
    {
        return $this->belongsTo(Santri::class, 'id_santri', 'id');
    }

    public function tagihan(): BelongsTo
    {
        return $this->belongsTo(TagihanSantri::class, 'id_tagihan', 'id');
    }

    public function pencatat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dicatat_oleh', 'id_pengguna');
    }

    public function pemverifikasi(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diverifikasi_oleh', 'id_pengguna');
    }

    public function rekening(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'kode_rekening', 'kode_coa');
    }
}
