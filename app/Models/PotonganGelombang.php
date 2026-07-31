<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Master potongan uang pangkal per gelombang/jenjang/tahun ajaran. */
class PotonganGelombang extends Model
{
    protected $table = 'potongan_gelombang';

    protected $guarded = ['id'];

    /** Jenjangnya — layar menyebut NAMA-nya, bukan kode `J001`. Null = semua jenjang. */
    public function jenjang(): BelongsTo
    {
        return $this->belongsTo(Jenjang::class, 'kode_jenjang', 'kode');
    }

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
