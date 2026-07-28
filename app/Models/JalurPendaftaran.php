<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Master Jalur Pendaftaran. `kode` dipakai sebagai nilai santri.jalur.
 */
class JalurPendaftaran extends Model
{
    protected $table = 'jalur_pendaftaran';

    protected $primaryKey = 'kode';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['kode', 'nama', 'tahun_ajaran', 'keterangan', 'status'];
}
