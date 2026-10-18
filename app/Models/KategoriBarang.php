<?php

namespace App\Models;

use App\Support\Audit\MencatatJejak;
use Illuminate\Database\Eloquent\Model;

/** Kategori barang toko (Makanan, Minuman, Alat Tulis, …). */
class KategoriBarang extends Model
{
    use MencatatJejak;

    protected $jejakModul = 'kategori-barang';

    protected $table = 'kategori_barang';

    protected $primaryKey = 'kode';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['kode', 'nama', 'status'];
}
