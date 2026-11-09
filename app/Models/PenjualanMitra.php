<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu bayar kartu di kantin mitra — lihat [[App\Services\Modules\KasirMitraService]]. `nomor` = nomor rujukan
 * potongan di ERP Al Wafi; `ref_dompet_batal` = nomor pengembaliannya bila dibatalkan.
 */
class PenjualanMitra extends Model
{
    protected $table = 'penjualan_mitra';

    protected $fillable = [
        'nomor', 'id_mitra', 'id_kasir', 'waktu', 'total', 'uid_kartu', 'nama_santri', 'sisa_saldo', 'status', 'pesan',
        'kunci_klien', 'ref_dompet_batal', 'alasan_batal', 'dibatalkan_pada', 'dibatalkan_oleh',
    ];

    protected function casts(): array
    {
        return ['waktu' => 'datetime', 'total' => 'decimal:2', 'sisa_saldo' => 'decimal:2', 'dibatalkan_pada' => 'datetime'];
    }

    public function mitra(): BelongsTo
    {
        return $this->belongsTo(Mitra::class, 'id_mitra');
    }

    public function rincian(): HasMany
    {
        return $this->hasMany(PenjualanMitraRincian::class, 'id_penjualan_mitra');
    }

    public function kasir(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_kasir', 'id_pengguna');
    }
}
