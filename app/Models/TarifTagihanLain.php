<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu sel matriks tarif kegiatan: (jenis biaya × jenjang) → nominal.
 *
 * TIDAK ADA BARIS = jenjang itu tidak ikut kegiatannya, dan santrinya tak bisa
 * didaftarkan sebagai peserta. Berbeda dari `tarif_biaya`, di mana ketiadaan
 * baris berarti "belum diisi" dan menghentikan penagihan dengan pesan.
 */
class TarifTagihanLain extends Model
{
    protected $table = 'tarif_tagihan_lain';

    protected $guarded = [];

    public function jenis(): BelongsTo
    {
        return $this->belongsTo(JenisBiaya::class, 'kode_jenis', 'kode');
    }

    public function jenjang(): BelongsTo
    {
        return $this->belongsTo(Jenjang::class, 'kode_jenjang', 'kode');
    }
}
