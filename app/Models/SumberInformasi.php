<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Master Sumber Informasi PPSB ("dari mana calon tahu pesantren ini").
 * Murni daftar pilihan — tak ada alur program yang bercabang karenanya, jadi
 * barisnya bebas ditambah panitia.
 */
class SumberInformasi extends Model
{
    protected $table = 'sumber_informasi';

    protected $primaryKey = 'kode';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['bawaan' => 'boolean', 'butuh_keterangan' => 'boolean'];
    }

    /** Opsi dropdown [kode => nama] untuk sumber aktif. */
    public static function opsi(): array
    {
        return static::where('status', 'aktif')->orderBy('urutan')->orderBy('kode')
            ->pluck('nama', 'kode')->all();
    }

    /** Label semua sumber (termasuk nonaktif) — dipakai dashboard membaca data lama. */
    public static function label(): array
    {
        return static::orderBy('urutan')->orderBy('kode')->pluck('nama', 'kode')->all();
    }

    /** Kode yang menuntut isian keterangan bebas (mis. "Lainnya"). */
    public static function kodeButuhKeterangan(): array
    {
        return static::where('butuh_keterangan', true)->pluck('kode')->all();
    }
}
