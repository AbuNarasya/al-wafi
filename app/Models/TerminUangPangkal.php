<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Baris jadwal angsuran uang pangkal (status FIFO dari sisa tagihan). */
class TerminUangPangkal extends Model
{
    protected $table = 'termin_uang_pangkal';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'urutan' => 'integer',
            'nominal' => 'decimal:2',
            'jatuh_tempo' => 'date',
            'diingatkan_pada' => 'date',
        ];
    }

    public function rencana(): BelongsTo
    {
        return $this->belongsTo(RencanaAngsuranUangPangkal::class, 'id_rencana', 'id');
    }
}
