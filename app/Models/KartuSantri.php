<?php

namespace App\Models;

use App\Support\Audit\MencatatJejak;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kartu RFID santri — UID ↔ santri. Satu santri satu kartu AKTIF; UID unik selamanya.
 * Lihat [[App\Services\Modules\KartuSantriService]].
 */
class KartuSantri extends Model
{
    use MencatatJejak;

    protected $jejakModul = 'kartu-santri';

    protected $table = 'kartu_santri';

    protected $fillable = [
        'uid', 'id_santri', 'status', 'alasan_blokir', 'diblokir_pada', 'diblokir_oleh', 'diblokir_oleh_wali',
        'dipasang_oleh', 'id_batch', 'dilepas_pada', 'dilepas_oleh',
    ];

    protected function casts(): array
    {
        return ['diblokir_pada' => 'datetime', 'dilepas_pada' => 'datetime'];
    }

    public function santri(): BelongsTo
    {
        return $this->belongsTo(Santri::class, 'id_santri', 'id');
    }
}
