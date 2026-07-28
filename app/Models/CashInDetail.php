<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Baris kas masuk. */
class CashInDetail extends Model
{
    protected $table = 'cash_in_details';

    public $timestamps = false;

    protected $fillable = [
        'kode_transaksi', 'kode_coa', 'nama_coa', 'jenis_kas_masuk', 'nominal',
        'keterangan', 'status_pengakuan', 'kode_persediaan', 'kuantiti', 'harga_satuan',
    ];

    protected function casts(): array
    {
        return [
            'nominal' => 'decimal:2',
            'kuantiti' => 'decimal:4',
            'harga_satuan' => 'decimal:2',
        ];
    }

    public function cashIn(): BelongsTo
    {
        return $this->belongsTo(CashIn::class, 'kode_transaksi', 'kode_transaksi');
    }
}
