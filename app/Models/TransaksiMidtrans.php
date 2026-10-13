<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pesanan pembayaran online (Midtrans Snap, Virtual Account) dari Portal Wali.
 * Pesanan sendiri tak pernah menyentuh buku besar — lihat PembayaranMidtrans.
 */
class TransaksiMidtrans extends Model
{
    protected $table = 'transaksi_midtrans';

    protected $guarded = ['id'];

    public const LABEL_STATUS = [
        'menunggu' => 'Menunggu pembayaran', 'lunas' => 'Lunas', 'kedaluwarsa' => 'Kedaluwarsa',
        'gagal' => 'Gagal', 'batal' => 'Dibatalkan',
    ];

    public const LABEL_BANK = [
        'bca' => 'BCA', 'bni' => 'BNI', 'bri' => 'BRI', 'mandiri' => 'Mandiri', 'permata' => 'Permata',
        'bsi' => 'BSI', 'cimb' => 'CIMB Niaga',
    ];

    protected function casts(): array
    {
        return [
            'nominal' => 'decimal:2', 'biaya' => 'decimal:2', 'total' => 'decimal:2',
            'kedaluwarsa_pada' => 'datetime', 'dibayar_pada' => 'datetime',
        ];
    }

    public function wali(): BelongsTo
    {
        return $this->belongsTo(Wali::class, 'id_wali');
    }

    public function tagihan(): BelongsTo
    {
        return $this->belongsTo(TagihanSantri::class, 'id_tagihan');
    }

    /** Rincian tagihan untuk pesanan `bayar_kelompok`. */
    public function rincian(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TransaksiMidtransTagihan::class, 'id_transaksi')->orderBy('id');
    }

    public function pembayaran(): BelongsTo
    {
        return $this->belongsTo(PembayaranSantri::class, 'id_pembayaran');
    }

    public function mutasi(): BelongsTo
    {
        return $this->belongsTo(MutasiDompet::class, 'id_mutasi');
    }

    public function labelStatus(): string
    {
        return self::LABEL_STATUS[$this->status] ?? $this->status;
    }

    public function labelBank(): ?string
    {
        return $this->bank ? (self::LABEL_BANK[$this->bank] ?? strtoupper($this->bank)) : null;
    }
}
