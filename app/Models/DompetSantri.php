<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Dompet santri (uang jajan; tak bisa topup tunai). */
class DompetSantri extends Model
{
    protected $table = 'dompet_santri';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['saldo' => 'decimal:2', 'kunci_tarik' => 'boolean'];
    }

    public function santri(): BelongsTo
    {
        return $this->belongsTo(Santri::class, 'id_santri', 'id');
    }
}
