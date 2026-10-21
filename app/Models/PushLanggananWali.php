<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Satu perangkat milik SATU orang di keluarga (ayah/ibu/wali) yang bersedia menerima push — lihat PushWaliService. */
class PushLanggananWali extends Model
{
    protected $table = 'push_langganan_wali';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['terakhir_berhasil' => 'datetime'];
    }
}
