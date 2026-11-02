<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Kelebihan kuota laundry satu santri satu bulan — draf → terbit (jadi tagihan lain-lain) / dikecualikan. */
class TagihanLaundry extends Model
{
    protected $table = 'tagihan_laundry';

    protected $guarded = ['id'];

    public const LABEL = ['draf' => 'Draf', 'terbit' => 'Terbit', 'dikecualikan' => 'Dikecualikan'];

    protected function casts(): array
    {
        return ['kg_total' => 'decimal:2', 'kuota_kg' => 'decimal:2', 'kg_lebih' => 'decimal:2', 'tarif_per_kg' => 'decimal:2',
            'nominal' => 'decimal:2', 'terbit_pada' => 'datetime'];
    }

    public function santri(): BelongsTo
    {
        return $this->belongsTo(Santri::class, 'id_santri', 'id');
    }

    public function tagihan(): BelongsTo
    {
        return $this->belongsTo(TagihanSantri::class, 'id_tagihan', 'id');
    }
}
