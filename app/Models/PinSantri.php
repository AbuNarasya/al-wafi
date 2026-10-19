<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** PIN belanja santri (dipasang wali dari portal) — lihat [[App\Services\Modules\KartuSantriService]]. */
class PinSantri extends Model
{
    protected $table = 'pin_santri';

    protected $primaryKey = 'id_santri';

    public $incrementing = false;

    protected $fillable = ['id_santri', 'pin_hash', 'gagal', 'terkunci_sampai', 'dipasang_oleh_wali', 'dipasang_oleh_peran'];

    protected $hidden = ['pin_hash'];

    protected function casts(): array
    {
        return ['terkunci_sampai' => 'datetime'];
    }
}
