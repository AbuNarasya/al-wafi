<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riwayat NIS seorang santri. `santri.nis` memegang yang BERLAKU; baris di sini
 * menyimpan seluruhnya, termasuk nomor lama dari jenjang sebelumnya.
 */
class NisSantri extends Model
{
    protected $table = 'nis_santri';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tingkat' => 'integer',
            'urut' => 'integer',
            'berlaku' => 'boolean',
            'diterbitkan_pada' => 'date',
        ];
    }

    public function santri(): BelongsTo
    {
        return $this->belongsTo(Santri::class, 'id_santri', 'id');
    }
}
