<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Master potongan uang pangkal per gelombang/jenjang/tahun ajaran. */
class PotonganGelombang extends Model
{
    protected $table = 'potongan_gelombang';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'gelombang' => 'integer',
            'potongan' => 'decimal:2',
            'masa_berlaku_hari' => 'integer',
            'aktif' => 'boolean',
        ];
    }
}
