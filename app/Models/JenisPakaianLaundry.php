<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Master jenis pakaian laundry (Gamis, Handuk, Sarung, …). Nonaktif = tak muncul di penerimaan baru. */
class JenisPakaianLaundry extends Model
{
    protected $table = 'jenis_pakaian_laundry';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['aktif' => 'boolean'];
    }
}
