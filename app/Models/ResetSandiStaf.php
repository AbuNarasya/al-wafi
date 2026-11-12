<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu tautan reset kata sandi staf (sekali pakai) — lihat App\Services\Modules\ResetSandiStafService. */
class ResetSandiStaf extends Model
{
    protected $table = 'reset_sandi_staf';

    protected $fillable = ['id_pengguna', 'token_hash', 'kedaluwarsa_pada', 'dipakai_pada', 'ip'];

    protected function casts(): array
    {
        return ['kedaluwarsa_pada' => 'datetime', 'dipakai_pada' => 'datetime'];
    }

    public function pengguna(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_pengguna', 'id_pengguna');
    }
}
