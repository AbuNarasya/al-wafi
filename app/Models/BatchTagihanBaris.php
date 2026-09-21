<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu santri di dalam sebuah batch, berikut angka yang DIKUNCI saat draft
 * disusun.
 *
 * Dua kolom yang mudah tertukar:
 *  • `keputusan` — hasil penimbangan saat draft disusun (istilahnya sama
 *    dengan pratinjau: terbit / bebas / dilewati / terhalang);
 *  • `hasil`     — apa yang benar-benar terjadi saat rilis. `dilewati` di sini
 *    berarti GUGUR di antara otorisasi dan rilis, bukan gugur sejak awal.
 */
class BatchTagihanBaris extends Model
{
    protected $table = 'batch_tagihan_baris';

    public $timestamps = false;

    protected $guarded = ['id'];

    public const TERBIT = 'terbit';

    public const BEBAS = 'bebas';

    public const DILEWATI = 'dilewati';

    public const TERHALANG = 'terhalang';

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(BatchTagihan::class, 'id_batch', 'id');
    }

    public function santri(): BelongsTo
    {
        return $this->belongsTo(Santri::class, 'id_santri', 'id');
    }

    public function jenis(): BelongsTo
    {
        return $this->belongsTo(JenisBiaya::class, 'kode_jenis', 'kode');
    }

    public function tagihan(): BelongsTo
    {
        return $this->belongsTo(TagihanSantri::class, 'id_tagihan', 'id');
    }
}
