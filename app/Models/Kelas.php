<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Kelas / rombel (21 Okt 2026) — lihat migrasi 000089. */
class Kelas extends Model
{
    protected $table = 'kelas';

    protected $fillable = ['kode_jenjang', 'tingkat', 'nama', 'peruntukan', 'status', 'id_wali_kelas'];

    public function waliKelas(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_wali_kelas', 'id_pengguna');
    }

    public const PERUNTUKAN = ['L' => 'Putra', 'P' => 'Putri', 'C' => 'Campur'];

    public function jenjang(): BelongsTo
    {
        return $this->belongsTo(Jenjang::class, 'kode_jenjang', 'kode');
    }

    public function santri(): HasMany
    {
        return $this->hasMany(Santri::class, 'id_kelas', 'id');
    }

    public function labelPeruntukan(): string
    {
        return self::PERUNTUKAN[$this->peruntukan] ?? $this->peruntukan;
    }

    /** Boleh diisi santri berjenis kelamin ini? Kelas campur menerima keduanya. */
    public function menerima(?string $jenisKelamin): bool
    {
        return $this->peruntukan === 'C' || $this->peruntukan === strtoupper((string) $jenisKelamin);
    }
}
