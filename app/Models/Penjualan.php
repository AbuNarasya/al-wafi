<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu struk penjualan kasir. Stok sudah keluar FIFO saat struk disimpan; jurnalnya
 * menyusul RINGKAS saat shift ditutup. Tak memakai MencatatJejak: ribuan struk sehari
 * akan membanjiri log audit — riwayatnya sudah utuh di tabel ini & kartu stok.
 */
class Penjualan extends Model
{
    public const SUMBER = 'Penjualan';

    protected $table = 'penjualan';

    protected $primaryKey = 'id_penjualan';

    protected $fillable = [
        'nomor', 'id_shift', 'kode_lokasi', 'waktu', 'metode', 'total', 'bayar', 'kembalian', 'hpp',
        'status', 'id_kasir', 'alasan_batal', 'dibatalkan_oleh', 'dibatalkan_pada',
        'diskon_struk', 'disetujui_oleh', 'alasan_diskon',
        'id_santri', 'uid_kartu', 'id_mutasi_dompet', 'id_pencairan',
        // Kartu lewat penghubung (instans Wafi Mart): snapshot dari ERP Al Wafi.
        'nama_kartu', 'ref_dompet', 'sisa_saldo_kartu', 'ref_dompet_batal',
    ];

    public function santri(): BelongsTo
    {
        return $this->belongsTo(Santri::class, 'id_santri', 'id');
    }

    protected function casts(): array
    {
        return [
            'waktu' => 'datetime', 'dibatalkan_pada' => 'datetime',
            'total' => 'decimal:2', 'bayar' => 'decimal:2', 'kembalian' => 'decimal:2', 'hpp' => 'decimal:2',
            'diskon_struk' => 'decimal:2',
        ];
    }

    public function penyetuju(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disetujui_oleh', 'id_pengguna');
    }

    public function details(): HasMany
    {
        return $this->hasMany(PenjualanDetail::class, 'id_penjualan', 'id_penjualan')->orderBy('id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(ShiftKasir::class, 'id_shift', 'id_shift');
    }

    public function kasir(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_kasir', 'id_pengguna');
    }
}
