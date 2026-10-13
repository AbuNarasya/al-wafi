<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Rincian satu VA Midtrans yang membayar beberapa tagihan sekaligus (jenis `bayar_kelompok`). */
class TransaksiMidtransTagihan extends Model
{
    protected $table = 'transaksi_midtrans_tagihan';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['nominal' => 'decimal:2'];
    }

    public function transaksi(): BelongsTo
    {
        return $this->belongsTo(TransaksiMidtrans::class, 'id_transaksi');
    }

    public function tagihan(): BelongsTo
    {
        return $this->belongsTo(TagihanSantri::class, 'id_tagihan');
    }
}
