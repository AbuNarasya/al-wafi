<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** PIN pengawas kasir — lihat [[App\Services\Modules\PinPengawasService]]. */
class PinPengawas extends Model
{
    protected $table = 'pin_pengawas';

    protected $primaryKey = 'id_pengguna';

    public $incrementing = false;

    protected $fillable = ['id_pengguna', 'pin_hash', 'gagal', 'terkunci_sampai'];

    protected $hidden = ['pin_hash'];

    protected function casts(): array
    {
        return ['terkunci_sampai' => 'datetime'];
    }
}
