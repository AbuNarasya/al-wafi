<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Baris kas keluar. tipe: lainnya | invoice | inventory | pengajuan. */
class CashOutDetail extends Model
{
    protected $table = 'cash_out_details';

    public $timestamps = false;

    protected $fillable = [
        'kode_transaksi', 'tipe', 'kode_coa', 'nama_coa', 'nominal', 'keterangan',
        'id_invoice', 'id_pengajuan', 'kode_persediaan', 'kuantiti', 'harga_satuan',
    ];

    protected function casts(): array
    {
        return [
            'nominal' => 'decimal:2',
            'kuantiti' => 'decimal:4',
            'harga_satuan' => 'decimal:2',
        ];
    }

    public function cashOut(): BelongsTo
    {
        return $this->belongsTo(CashOut::class, 'kode_transaksi', 'kode_transaksi');
    }
}
