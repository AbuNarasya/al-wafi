<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Besaran layanan bersatuan: tarif per satuan, nama satuannya, dan kuota gratis
 * per periode. Satu baris per jenis biaya.
 *
 * Terpisah dari `jenis_biaya` mengikuti pembagian yang sudah berlaku di sini —
 * master menyimpan identitas akuntansi, besarannya tinggal di tabel sendiri,
 * sama seperti `tarif_biaya` dan `tarif_tagihan_lain`.
 */
class TarifPemakaian extends Model
{
    protected $table = 'tarif_pemakaian';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tarif_satuan' => 'decimal:2',
            'kuota_gratis' => 'decimal:2',
        ];
    }

    public function jenis(): BelongsTo
    {
        return $this->belongsTo(JenisBiaya::class, 'kode_jenis', 'kode');
    }
}
