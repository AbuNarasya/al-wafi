<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Master jenjang pendidikan. `kode` dirujuk sebagai string oleh tabel lain. */
class Jenjang extends Model
{
    protected $table = 'jenjang';

    protected $primaryKey = 'kode';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['kode', 'nama', 'urutan', 'status', 'keterangan'];

    protected function casts(): array
    {
        return ['urutan' => 'integer'];
    }
}
