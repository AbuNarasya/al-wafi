<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Kehadiran ujian seleksi — dicatat lewat pindaian QR kartu peserta. */
class KehadiranUjian extends Model
{
    protected $table = 'kehadiran_ujian';

    protected $fillable = ['id_santri', 'tahun_ajaran', 'hadir_pada', 'dicatat_oleh'];

    protected function casts(): array
    {
        return ['hadir_pada' => 'datetime'];
    }

    public function santri(): BelongsTo
    {
        return $this->belongsTo(Santri::class, 'id_santri');
    }

    public function pencatat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dicatat_oleh', 'id_pengguna');
    }
}
