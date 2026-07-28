<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Subledger tagihan per santri. sudah_akrual = penentu sisi kredit saat bayar. */
class TagihanSantri extends Model
{
    protected $table = 'tagihan_santri';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'nominal' => 'decimal:2',
            'sisa' => 'decimal:2',
            'sudah_akrual' => 'boolean',
            'jatuh_tempo' => 'date',
        ];
    }

    public function santri(): BelongsTo
    {
        return $this->belongsTo(Santri::class, 'id_santri', 'id');
    }

    public function jenis(): BelongsTo
    {
        return $this->belongsTo(JenisBiaya::class, 'kode_jenis', 'kode');
    }

    public function pembayaran(): HasMany
    {
        return $this->hasMany(PembayaranSantri::class, 'id_tagihan', 'id');
    }

    public function rencanaAngsuran(): HasMany
    {
        return $this->hasMany(RencanaAngsuranUangPangkal::class, 'id_tagihan', 'id');
    }

    public function potongan(): HasOne
    {
        return $this->hasOne(PotonganUangPangkal::class, 'id_tagihan', 'id');
    }
}
