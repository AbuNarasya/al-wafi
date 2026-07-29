<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Master Jalur Pendaftaran. `kode` dipakai sebagai nilai santri.jalur.
 *
 * BERLAKU LINTAS TAHUN AJARAN — jalur tidak lagi terikat satu T.A. Yang
 * membedakan tarif per tahun adalah `jenis_biaya` (tahun_ajaran + kode_jalur),
 * bukan masternya.
 */
class JalurPendaftaran extends Model
{
    protected $table = 'jalur_pendaftaran';

    protected $primaryKey = 'kode';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['kode', 'nama', 'keterangan', 'status'];
}
