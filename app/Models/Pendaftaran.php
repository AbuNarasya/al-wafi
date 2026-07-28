<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Berkas proses penerimaan (tes, med check). */
class Pendaftaran extends Model
{
    protected $table = 'pendaftaran';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'verifikasi_ok' => 'boolean',
            'nilai_baca' => 'decimal:2',
            'nilai_akademik' => 'decimal:2',
            'medcheck_ok' => 'boolean',
            'dokumen_lengkap' => 'boolean',
        ];
    }

    public function santri(): BelongsTo
    {
        return $this->belongsTo(Santri::class, 'id_santri', 'id');
    }
}
