<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Perangkat yang diingat setelah wali masuk dengan kode — lihat App\Services\PortalWali\IngatPerangkat. */
class PerangkatWali extends Model
{
    protected $table = 'perangkat_wali';

    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'terakhir_dipakai' => 'datetime',
            'kedaluwarsa_pada' => 'datetime',
            'dicabut_pada' => 'datetime',
        ];
    }

    public function wali(): BelongsTo
    {
        return $this->belongsTo(Wali::class, 'id_wali');
    }

    public function berlaku(): bool
    {
        return $this->dicabut_pada === null && $this->kedaluwarsa_pada->isFuture();
    }
}
