<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Obat yang diberikan pada satu kunjungan klinik — dicatat saja, tidak mengurangi stok. */
class ObatKunjunganKlinik extends Model
{
    public $timestamps = false;

    protected $table = 'obat_kunjungan_klinik';

    protected $fillable = ['id_kunjungan', 'nama', 'dosis', 'jumlah'];
}
