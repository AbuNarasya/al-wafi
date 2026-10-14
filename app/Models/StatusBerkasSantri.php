<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Keputusan staf atas satu jenis berkas santri — lihat DokumenSantriService::statusBerkas(). */
class StatusBerkasSantri extends Model
{
    protected $table = 'status_berkas_santri';

    protected $guarded = ['id'];
}
