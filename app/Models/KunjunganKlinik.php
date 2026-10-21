<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Kunjungan santri ke klinik — lihat migrasi 000092 & KlinikService. */
class KunjunganKlinik extends Model
{
    protected $table = 'kunjungan_klinik';

    protected $guarded = ['id'];

    public const TINDAK_LANJUT = [
        'kembali_kelas' => 'Kembali ke kelas', 'istirahat_asrama' => 'Istirahat di asrama', 'dirawat' => 'Dirawat di klinik',
        'dirujuk' => 'Dirujuk ke rumah sakit', 'dijemput' => 'Dijemput wali',
    ];

    protected function casts(): array
    {
        return ['datang_pada' => 'datetime', 'selesai_pada' => 'datetime', 'suhu' => 'decimal:1', 'berat' => 'decimal:1'];
    }

    public function santri(): BelongsTo
    {
        return $this->belongsTo(Santri::class, 'id_santri');
    }

    public function obat(): HasMany
    {
        return $this->hasMany(ObatKunjunganKlinik::class, 'id_kunjungan');
    }

    public function tagihan(): BelongsTo
    {
        return $this->belongsTo(TagihanSantri::class, 'id_tagihan');
    }

    public function pencatat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dicatat_oleh', 'id_pengguna');
    }

    public function labelTindakLanjut(): string
    {
        return self::TINDAK_LANJUT[$this->tindak_lanjut] ?? $this->tindak_lanjut;
    }
}
