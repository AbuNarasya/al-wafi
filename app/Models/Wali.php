<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Satu WALI = satu keluarga (berbagi satu Dompet Wali).
 *
 * Authenticatable untuk guard `wali` (Portal Wali). Tanpa sandi & tanpa
 * remember_token: masuk lewat kode OTP email, "ingat perangkat" lewat
 * tabel `perangkat_wali`. SIAPA di keluarga itu yang masuk (ayah/ibu/wali)
 * disimpan di sesi — lihat App\Services\PortalWali\SesiWali.
 */
class Wali extends Model implements AuthenticatableContract
{
    use Authenticatable;

    protected $table = 'wali';

    /**
     * Email keluarga diubah staf → semua perangkat yang diingat gugur. Email
     * adalah identitas masuk portal; perangkat yang diingat atas email lama tak
     * boleh tetap terbuka bagi pemegang email yang sudah tak berhak.
     */
    protected static function booted(): void
    {
        static::updated(function (Wali $wali) {
            if ($wali->wasChanged(['email_ayah', 'email_ibu', 'email_wali', 'status'])) {
                \App\Services\PortalWali\IngatPerangkat::cabutSemua($wali->id);
            }
        });
    }

    /** Tak ada kolom remember_token — "ingat saya" ditangani perangkat_wali. */
    public function getRememberTokenName(): string
    {
        return '';
    }

    protected $guarded = ['id'];

    /** Peran yang datanya disimpan lengkap (nama/telepon/email/pekerjaan/pendapatan). */
    public const PERAN = ['ayah' => 'Ayah', 'ibu' => 'Ibu', 'wali' => 'Wali'];

    /**
     * Rentang pendapatan — kodenya yang disimpan, bukan labelnya. Ditaruh di
     * model supaya form isian & berkas unduhan menyebut rentang yang sama;
     * saat masih hidup di dalam blade form, unduhan hanya bisa menampilkan
     * kode mentahnya (`juta_5_10`).
     */
    public const PENDAPATAN = [
        'di_bawah_5' => '< Rp 5 juta', 'juta_5_10' => 'Rp 5–10 juta',
        'juta_10_15' => 'Rp 10–15 juta', 'juta_15_25' => 'Rp 15–25 juta', 'di_atas_25' => '> Rp 25 juta',
    ];

    protected function casts(): array
    {
        return [
            'auto_debet' => 'boolean',
            'telepon_verified' => 'boolean',
        ];
    }

    public function santri(): HasMany
    {
        return $this->hasMany(Santri::class, 'id_wali', 'id');
    }

    public function dompet(): HasOne
    {
        return $this->hasOne(DompetWali::class, 'id_wali', 'id');
    }
}
