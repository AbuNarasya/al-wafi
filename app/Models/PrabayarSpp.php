<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Saldo SPP dibayar di muka. */
class PrabayarSpp extends Model
{
    protected $table = 'prabayar_spp';

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
