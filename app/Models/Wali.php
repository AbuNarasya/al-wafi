<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Satu WALI = satu keluarga (berbagi satu Dompet Wali). */
class Wali extends Model
{
    protected $table = 'wali';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'auto_debet' => 'boolean',
            'telepon_verified' => 'boolean',
            'otp_expires' => 'datetime',
        ];
    }

    public function santri(): HasMany
    {
        return $this->hasMany(Santri::class, 'id_wali', 'id');
    }

    public function dompet(): HasOne
    {
        return $this->hasOne(DompetWali::class, 'id_wali', 'id');
    }
}
