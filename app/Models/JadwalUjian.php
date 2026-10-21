<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Jadwal ujian seleksi PSB — lihat migrasi 000090. */
class JadwalUjian extends Model
{
    protected $table = 'jadwal_ujian';

    protected $fillable = ['tahun_ajaran', 'kode_jenjang', 'kode_gelombang', 'tanggal', 'jam', 'tempat', 'keterangan'];

    protected function casts(): array
    {
        return ['tanggal' => 'date'];
    }

    public function jenjang(): BelongsTo
    {
        return $this->belongsTo(Jenjang::class, 'kode_jenjang', 'kode');
    }
}
