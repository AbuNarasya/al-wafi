<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Dompet keluarga (wadi'ah/titipan — liabilitas). */
class DompetWali extends Model
{
    protected $table = 'dompet_wali';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['saldo' => 'decimal:2'];
    }

    public function wali(): BelongsTo
    {
        return $this->belongsTo(Wali::class, 'id_wali', 'id');
    }
}
