<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Tipe Perizinan Insidental — lima tipe tetap; batas waktu & saklar aktif diatur di Pengaturan Perizinan. */
class TipeIzin extends Model
{
    protected $table = 'tipe_izin';

    protected $primaryKey = 'kode';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['pulang' => 'boolean', 'wajib_keperluan' => 'boolean', 'aktif' => 'boolean', 'batas_jam' => 'integer'];
    }
}
