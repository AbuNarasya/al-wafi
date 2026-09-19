<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu lot FIFO. Tiap pemasukan melahirkan satu lapisan berharga sendiri;
 * pengeluaran menggerus `kuantiti_sisa` lapisan TERTUA lebih dulu.
 *
 * Urutan FIFO = (tanggal, id). `id` wajib jadi pemutus seri: dua pemasukan
 * bertanggal sama tanpa pemutus akan diurutkan sesuka PostgreSQL, dan harga
 * pokoknya berubah-ubah antar pemanggilan.
 */
class LapisanPersediaan extends Model
{
    protected $table = 'lapisan_persediaan';

    protected $fillable = [
        'kode_persediaan', 'tanggal', 'kuantiti_awal', 'kuantiti_sisa',
        'harga_satuan', 'mutasi_id', 'sumber_modul', 'sumber_ref',
    ];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'kuantiti_awal' => 'decimal:4',
            'kuantiti_sisa' => 'decimal:4',
            'harga_satuan' => 'decimal:2',
        ];
    }

    public function persediaan(): BelongsTo
    {
        return $this->belongsTo(Inventory::class, 'kode_persediaan', 'kode_persediaan');
    }
}
