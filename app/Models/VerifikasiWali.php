<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu link verifikasi data untuk satu keluarga — lihat App\Services\PortalWali\LinkVerifikasi.
 * Kodenya sendiri tak pernah disimpan polos: `token_hash` untuk mencocokkan,
 * `token_enkripsi` (APP_KEY) supaya staf bisa mengirim ulang link yang sama.
 */
class VerifikasiWali extends Model
{
    protected $table = 'verifikasi_wali';

    protected $guarded = ['id'];

    protected $hidden = ['token_hash', 'token_enkripsi'];

    /** Urutan tampil status di layar staf. */
    public const STATUS = [
        'belum_mulai' => 'Belum mulai', 'dibuat' => 'Belum dikirim', 'terkirim' => 'Terkirim',
        'dibuka' => 'Sudah dibuka', 'selesai' => 'Selesai', 'kedaluwarsa' => 'Kedaluwarsa',
    ];

    protected function casts(): array
    {
        return [
            'berlaku_mulai' => 'datetime', 'berlaku_sampai' => 'datetime',
            'dikirim_pada' => 'datetime', 'dibuka_pertama' => 'datetime', 'dibuka_terakhir' => 'datetime',
            'selesai_pada' => 'datetime', 'dicabut_pada' => 'datetime',
        ];
    }

    public function wali(): BelongsTo
    {
        return $this->belongsTo(Wali::class, 'id_wali', 'id');
    }

    /** Bisa dipakai SAAT INI: belum dicabut, belum selesai, dan di dalam masa berlaku. */
    public function bisaDipakai(): bool
    {
        return $this->dicabut_pada === null && $this->selesai_pada === null
            && now()->greaterThanOrEqualTo($this->berlaku_mulai) && now()->lessThan($this->berlaku_sampai);
    }

    public function status(): string
    {
        return match (true) {
            $this->selesai_pada !== null => 'selesai',
            now()->greaterThanOrEqualTo($this->berlaku_sampai) => 'kedaluwarsa',
            now()->lessThan($this->berlaku_mulai) => 'belum_mulai',
            $this->dibuka_pertama !== null => 'dibuka',
            $this->dikirim_pada !== null => 'terkirim',
            default => 'dibuat',
        };
    }
}
