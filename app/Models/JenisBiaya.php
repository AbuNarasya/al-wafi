<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Master jenis biaya kesantrian (registrasi/uang_pangkal/spp/lain). */
class JenisBiaya extends Model
{
    protected $table = 'jenis_biaya';

    protected $primaryKey = 'kode';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['nominal' => 'decimal:2', 'berulang' => 'boolean'];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class, 'kode_unit', 'kode_unit');
    }

    public function jenjang(): BelongsTo
    {
        return $this->belongsTo(Jenjang::class, 'kode_jenjang', 'kode');
    }

    public function jalur(): BelongsTo
    {
        return $this->belongsTo(JalurPendaftaran::class, 'kode_jalur', 'kode');
    }

    /**
     * Baris master yang BERLAKU untuk satu santri — sumber tunggal aturan tarif
     * (dipakai registrasi, uang pangkal, & SPP). Kolom kosong artinya "berlaku
     * untuk semua nilai kolom itu", jadi pencarian berjalan dari yang paling
     * khusus ke paling umum:
     *   1. jenjang + jalur  → tarif pengecualian paling spesifik (mis. SMP OSS)
     *   2. jalur saja       → pengecualian lintas jenjang (mis. beasiswa yatim)
     *   3. jenjang saja     → tarif DASAR jenjang itu (berlaku semua jalur)
     *   4. tanpa keduanya   → tarif UMUM
     * Jalur sengaja menang atas jenjang di langkah 2: baris berjalur memang
     * dibuat sebagai PENGECUALIAN terhadap tarif dasar jenjang.
     *
     * Sengaja TIDAK ada fallback "ambil baris aktif apa saja" — dulu ada, dan
     * itulah yang membuat calon SMP reguler diam-diam ditagih tarif SMP OSS.
     * Lebih baik gagal dengan pesan menuntun daripada salah nominal.
     */
    public static function berlaku(string $tipe, ?string $tahunAjaran, ?string $kodeJenjang = null, ?string $kodeJalur = null): ?self
    {
        // $tipe di sini adalah PERILAKU (registrasi/uang_pangkal/spp/lain), bukan
        // kode tipe — master Tipe Biaya boleh punya banyak kode berperilaku sama.
        $kodeTipe = TipeBiaya::kode($tipe);

        $cari = function (?string $jenjang, ?string $jalur) use ($kodeTipe, $tahunAjaran) {
            return static::whereIn('tipe', $kodeTipe)->where('status', 'aktif')
                ->when($tahunAjaran, fn ($q) => $q->where('tahun_ajaran', $tahunAjaran))
                ->when($jenjang, fn ($q) => $q->where('kode_jenjang', $jenjang), fn ($q) => $q->whereNull('kode_jenjang'))
                ->when($jalur, fn ($q) => $q->where('kode_jalur', $jalur), fn ($q) => $q->whereNull('kode_jalur'))
                ->orderBy('kode')->first();
        };

        $baris = null;
        if ($kodeJenjang && $kodeJalur) {
            $baris = $cari($kodeJenjang, $kodeJalur);
        }
        if (! $baris && $kodeJalur) {
            $baris = $cari(null, $kodeJalur);
        }
        if (! $baris && $kodeJenjang) {
            $baris = $cari($kodeJenjang, null);
        }

        return $baris ?? $cari(null, null);
    }
}
