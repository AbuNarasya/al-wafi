<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PENGECUALIAN: jalur pendaftaran ini TIDAK BERLAKU pada (tahun ajaran, jenjang)
 * tertentu — mis. SDTQ tak punya jalur OSS maupun jalur lanjutan mana pun, dan
 * SMA tak punya jalur OSS (OSS hanya dibuka di SMP).
 *
 * Yang disimpan hanya PENGECUALIANNYA. Tak ada baris = jalurnya berlaku; dengan
 * begitu data lama tetap sah tanpa perlu diisi apa pun lebih dulu, dan lupa
 * mengisi tak pernah berakibat jalur hilang diam-diam.
 */
class JalurNonaktif extends Model
{
    protected $table = 'jalur_nonaktif';

    protected $guarded = [];

    public function jenjang(): BelongsTo
    {
        return $this->belongsTo(Jenjang::class, 'kode_jenjang', 'kode');
    }

    public function jalur(): BelongsTo
    {
        return $this->belongsTo(JalurPendaftaran::class, 'kode_jalur', 'kode');
    }

    /**
     * Kode jalur yang dinonaktifkan pada satu (T.A, jenjang).
     *
     * @return list<string>
     */
    public static function kodeUntuk(?string $tahunAjaran, ?string $kodeJenjang): array
    {
        if (! $tahunAjaran || ! $kodeJenjang) {
            return [];
        }

        return static::where('tahun_ajaran', $tahunAjaran)
            ->where('kode_jenjang', $kodeJenjang)
            ->pluck('kode_jalur')->all();
    }
}
