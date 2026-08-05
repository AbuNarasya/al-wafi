<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Snapshot + siklus potongan pada satu tagihan uang pangkal (1:1). */
class PotonganUangPangkal extends Model
{
    protected $table = 'potongan_uang_pangkal';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'nominal_normal' => 'decimal:2',
            'potongan' => 'decimal:2',
            'syarat_persen' => 'integer',
            'tenggat' => 'date',
            'dinilai_pada' => 'datetime',
        ];
    }

    public function tagihan(): BelongsTo
    {
        return $this->belongsTo(TagihanSantri::class, 'id_tagihan', 'id');
    }
}
