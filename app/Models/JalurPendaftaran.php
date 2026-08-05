<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Master Jalur Pendaftaran. `kode` dipakai sebagai nilai santri.jalur.
 *
 * BERLAKU LINTAS TAHUN AJARAN — jalur tidak lagi terikat satu T.A. Yang
 * membedakan tarif per tahun adalah `jenis_biaya` (tahun_ajaran + kode_jalur),
 * bukan masternya.
 */
class JalurPendaftaran extends Model
{
    protected $table = 'jalur_pendaftaran';

    protected $primaryKey = 'kode';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['kode', 'nama', 'kode_jalur_lanjutan', 'bebas_uang_pangkal', 'keterangan', 'status', 'urutan'];

    protected function casts(): array
    {
        return ['bebas_uang_pangkal' => 'boolean', 'urutan' => 'integer'];
    }

    /** Jalur yang berlaku setelah santri naik jenjang; null = jalurnya tak berubah. */
    public function lanjutan(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(self::class, 'kode_jalur_lanjutan', 'kode');
    }

    /**
     * Santri berjalur ini bebas uang pangkal? Dipakai dua tempat: penerbitan
     * tagihan saat mendaftar, dan proses kenaikan jenjang.
     */
    public static function bebasUangPangkal(?string $kode): bool
    {
        return $kode !== null && (bool) static::whereKey($kode)->value('bebas_uang_pangkal');
    }
}
