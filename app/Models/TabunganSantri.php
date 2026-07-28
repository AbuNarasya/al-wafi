<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tabungan santri (terpisah dari dompet jajan). */
class TabunganSantri extends Model
{
    protected $table = 'tabungan_santri';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['saldo' => 'decimal:2'];
    }

    public function santri(): BelongsTo
    {
        return $this->belongsTo(Santri::class, 'id_santri', 'id');
    }
}
