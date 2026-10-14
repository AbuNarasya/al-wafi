<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Password Portal Wali per orang (keluarga + peran) — lihat App\Services\PortalWali\KataSandiWali. */
class SandiWali extends Model
{
    protected $table = 'sandi_wali';

    protected $guarded = ['id'];

    protected $hidden = ['sandi_hash'];
}
