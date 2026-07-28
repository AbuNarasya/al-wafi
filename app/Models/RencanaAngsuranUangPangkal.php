<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Kesepakatan angsuran uang pangkal (ber-versi, tidak berjurnal). */
class RencanaAngsuranUangPangkal extends Model
{
    protected $table = 'rencana_angsuran_uang_pangkal';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['versi' => 'integer', 'disepakati_pada' => 'date'];
    }

    public function tagihan(): BelongsTo
    {
        return $this->belongsTo(TagihanSantri::class, 'id_tagihan', 'id');
    }

    public function termin(): HasMany
    {
        return $this->hasMany(TerminUangPangkal::class, 'id_rencana', 'id');
    }
}
