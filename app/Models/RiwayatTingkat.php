<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Di mana seorang santri berada pada satu tahun ajaran — satu baris per
 * (santri, T.A). Tanpa tabel ini, "kelas berapa dia tahun lalu" hanya bisa
 * dijawab dengan menebak dari tingkat sekarang dikurangi selisih tahun, dan
 * tebakan itu langsung salah begitu ada santri mengulang atau masuk di tengah
 * jenjang.
 */
class RiwayatTingkat extends Model
{
    protected $table = 'riwayat_tingkat';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['tingkat' => 'integer'];
    }

    public function santri(): BelongsTo
    {
        return $this->belongsTo(Santri::class, 'id_santri', 'id');
    }

    public function jenjang(): BelongsTo
    {
        return $this->belongsTo(Jenjang::class, 'kode_jenjang', 'kode');
    }
}
