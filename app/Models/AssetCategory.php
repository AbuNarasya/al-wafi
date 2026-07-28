<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Master kategori aset tetap. */
class AssetCategory extends Model
{
    protected $table = 'asset_categories';

    protected $primaryKey = 'kode_kategori';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['kode_kategori', 'nama', 'keterangan', 'status'];
}
