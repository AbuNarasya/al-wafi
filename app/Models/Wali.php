<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Satu WALI = satu keluarga (berbagi satu Dompet Wali). */
class Wali extends Model
{
    protected $table = 'wali';

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
            'otp_expires' => 'datetime',
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
