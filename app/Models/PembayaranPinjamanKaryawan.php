<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu pembayaran cicilan. cara: tunai | potong_gaji. */
class PembayaranPinjamanKaryawan extends Model
{
    protected $table = 'pembayaran_pinjaman_karyawan';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['tanggal' => 'date', 'nominal' => 'decimal:2'];
    }

    public function pinjaman(): BelongsTo
    {
        return $this->belongsTo(PinjamanKaryawan::class, 'id_pinjaman', 'id');
    }
}
