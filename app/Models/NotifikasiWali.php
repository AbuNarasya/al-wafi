<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Kabar untuk SATU orang di sebuah keluarga (ayah/ibu/wali) — lihat KabarWali. */
class NotifikasiWali extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'notifikasi_wali';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['dibaca_pada' => 'datetime'];
    }
}
