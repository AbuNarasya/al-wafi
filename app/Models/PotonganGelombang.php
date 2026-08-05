<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SATU SEL matriks potongan: (T.A × gelombang × jenjang) → nominal.
 *
 * Tak memuat waktu sama sekali — periode & masa berlaku ada di master
 * `gelombang`, karena keduanya sifat gelombangnya, bukan sifat selnya. Dulu
 * ketiganya tersimpan di sini dan terduplikasi di tiap jenjang.
 *
 * Tak ada pula sel "Umum (semua jenjang)": jenjang selalu diketahui saat
 * menagih, jadi cadangan itu hanya menagihkan angka dari sel yang tampak kosong.
 */
class PotonganGelombang extends Model
{
    protected $table = 'potongan_gelombang';

    protected $guarded = ['id'];

    public function jenjang(): BelongsTo
    {
        return $this->belongsTo(Jenjang::class, 'kode_jenjang', 'kode');
    }

    public function master(): BelongsTo
    {
        return $this->belongsTo(Gelombang::class, 'gelombang', 'kode');
    }

    protected function casts(): array
    {
        return ['potongan' => 'decimal:2'];
    }
}
