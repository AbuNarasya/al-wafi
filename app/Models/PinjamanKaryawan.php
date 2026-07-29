<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Pinjaman kepada karyawan. status: aktif | lunas | void. */
class PinjamanKaryawan extends Model
{
    protected $table = 'pinjaman_karyawan';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['tanggal' => 'date', 'pokok' => 'decimal:2', 'terbayar' => 'decimal:2'];
    }

    public function karyawan(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class, 'kode_karyawan', 'kode');
    }

    public function termin(): HasMany
    {
        return $this->hasMany(TerminPinjamanKaryawan::class, 'id_pinjaman', 'id');
    }

    public function pembayaran(): HasMany
    {
        return $this->hasMany(PembayaranPinjamanKaryawan::class, 'id_pinjaman', 'id');
    }

    /** Sisa hutang = pokok − terbayar. Dihitung, bukan disimpan, agar tak bisa menyimpang. */
    public function getSisaAttribute(): string
    {
        return Money::sub($this->pokok, $this->terbayar);
    }
}
