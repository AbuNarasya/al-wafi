<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu catatan absen asrama kiriman ISE — lihat App\Services\Modules\AbsensiAsramaService. */
class AbsensiAsrama extends Model
{
    protected $table = 'absensi_asrama';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['waktu' => 'datetime', 'dikabarkan_pada' => 'datetime'];
    }

    public function santri(): BelongsTo
    {
        return $this->belongsTo(Santri::class, 'id_santri');
    }
}
