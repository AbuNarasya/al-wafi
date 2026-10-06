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
            'jeda_hangus_hari' => 'integer',
            'tenggat' => 'date',
            'dinilai_pada' => 'datetime',
        ];
    }

    public function tagihan(): BelongsTo
    {
        return $this->belongsTo(TagihanSantri::class, 'id_tagihan', 'id');
    }

    /**
     * Tutup potongan yang masih `berlaku` milik tagihan yang baru saja dibatalkan
     * atau dihapus. Uang pangkal dicatat cash basis selama calon: begitu
     * tagihannya tak berlaku, tak ada urusan potongan lagi. Tanpa ini potongannya
     * tetap `berlaku`, dan evaluasi berikutnya bisa membuatnya hangus —
     * menghidupkan tagihan yang sudah batal.
     */
    public static function batalkanUntuk(int $idTagihan): void
    {
        static::where('id_tagihan', $idTagihan)->where('status', 'berlaku')
            ->update(['status' => 'batal', 'dinilai_pada' => now()]);
    }
}
