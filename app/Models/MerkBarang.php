<?php

namespace App\Models;

use App\Support\Audit\MencatatJejak;
use Illuminate\Database\Eloquent\Model;

/** Merk barang toko. */
class MerkBarang extends Model
{
    use MencatatJejak;

    protected $jejakModul = 'merk-barang';

    protected $table = 'merk_barang';

    protected $primaryKey = 'kode';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['kode', 'nama', 'status', 'id_batch'];
}
