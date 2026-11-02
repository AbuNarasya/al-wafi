<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * IDENTITAS AKUNTANSI jenis biaya kesantrian: nama, perilaku (lewat `tipe`),
 * jenjang, pasangan akun COA, dan unit bisnis. Satu baris per (jenjang, perilaku)
 * — diisi sekali, tidak diduplikasi tiap tahun.
 *
 * BESARANNYA TIDAK DI SINI. Tarif tinggal di `tarif_biaya` (grid per tahun
 * ajaran × jenjang × jalur); lihat App\Services\Modules\TarifService.
 */
class JenisBiaya extends Model
{
    protected $table = 'jenis_biaya';

    protected $primaryKey = 'kode';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['berulang' => 'boolean'];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class, 'kode_unit', 'kode_unit');
    }

    public function jenjang(): BelongsTo
    {
        return $this->belongsTo(Jenjang::class, 'kode_jenjang', 'kode');
    }

    /**
     * Baris identitas akuntansi untuk satu (perilaku, jenjang).
     *
     * Menggantikan `berlaku()` yang dulu menebak lewat empat tingkat (jenjang+jalur
     * → jalur → jenjang → umum) karena tarif ikut menumpang di tabel ini. Sejak
     * tarif pindah ke `tarif_biaya`, yang perlu dicari hanyalah pasangan AKUN —
     * dan akun tidak berbeda per jalur maupun per tahun ajaran.
     *
     * Tersisa satu cadangan: baris tanpa jenjang, untuk pesantren yang memakai
     * satu pasang akun untuk semua jenjang. Cadangan ini aman justru karena ia
     * tak lagi menentukan nominal — salah tebak paling banter salah akun, dan
     * itu terlihat di jurnal; dulu salah tebak berarti salah menagih.
     */
    public static function untuk(string $perilaku, ?string $kodeJenjang): ?self
    {
        // $perilaku bukan kode tipe — master Tipe Biaya boleh punya banyak kode
        // berperilaku sama.
        $kodeTipe = TipeBiaya::kodeBerperilaku($perilaku);

        $cari = fn (?string $jenjang) => static::whereIn('tipe', $kodeTipe)->where('status', 'aktif')
            ->when($jenjang, fn ($q) => $q->where('kode_jenjang', $jenjang), fn ($q) => $q->whereNull('kode_jenjang'))
            ->orderBy('kode')->first();

        return ($kodeJenjang ? $cari($kodeJenjang) : null) ?? $cari(null);
    }
}
