<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu kali santri menyetor pemakaian — sekali menimbang cucian, misalnya.
 *
 * `id_tagihan` NULL = belum tertagih. Penanda itu, bukan perbandingan tanggal,
 * yang menentukan baris mana ikut penerbitan berikutnya: setoran yang telat
 * dicatat setelah tagihan periodenya terbit tetap tak bertanda, jadi ia terbawa
 * ke penerbitan sesudahnya alih-alih hilang atau tertagih dua kali.
 */
class SetoranPemakaian extends Model
{
    protected $table = 'setoran_pemakaian';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'kuantitas' => 'decimal:2',
        ];
    }

    public function scopeBelumTertagih($query)
    {
        return $query->whereNull('id_tagihan');
    }

    public function jenis(): BelongsTo
    {
        return $this->belongsTo(JenisBiaya::class, 'kode_jenis', 'kode');
    }

    public function santri(): BelongsTo
    {
        return $this->belongsTo(Santri::class, 'id_santri', 'id');
    }

    public function tagihan(): BelongsTo
    {
        return $this->belongsTo(TagihanSantri::class, 'id_tagihan', 'id');
    }

    public function pencatat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dicatat_oleh', 'id_pengguna');
    }
}
