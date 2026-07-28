<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/** Calon → santri (status = daur hidup PPSB). nominal_spp per anak. */
class Santri extends Model
{
    protected $table = 'santri';

    protected $guarded = ['id'];

    protected $appends = ['umur', 'umur_terbaca'];

    /** Umur (tahun penuh) dari tanggal_lahir — dihitung, tidak disimpan. */
    protected function umur(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->tanggal_lahir ? Carbon::parse($this->tanggal_lahir)->age : null,
        );
    }

    /** Umur terbaca "X tahun Y bulan". */
    protected function umurTerbaca(): Attribute
    {
        return Attribute::make(get: function () {
            if (! $this->tanggal_lahir) {
                return null;
            }
            $d = Carbon::parse($this->tanggal_lahir)->diff(Carbon::now());

            return "{$d->y} tahun {$d->m} bulan";
        });
    }

    protected function casts(): array
    {
        return [
            'tanggal_lahir' => 'date',
            'gelombang' => 'integer',
            'nominal_spp' => 'decimal:2',
        ];
    }

    public function wali(): BelongsTo
    {
        return $this->belongsTo(Wali::class, 'id_wali', 'id');
    }

    public function pendaftaran(): HasOne
    {
        return $this->hasOne(Pendaftaran::class, 'id_santri', 'id');
    }

    /** Jalur pendaftaran (reguler/pindahan/anak karyawan/…) — kolom `jalur` menyimpan kodenya. */
    public function jalurPendaftaran(): BelongsTo
    {
        return $this->belongsTo(JalurPendaftaran::class, 'jalur', 'kode');
    }

    public function dokumen(): HasMany
    {
        return $this->hasMany(DokumenSantri::class, 'id_santri', 'id');
    }

    public function tagihan(): HasMany
    {
        return $this->hasMany(TagihanSantri::class, 'id_santri', 'id');
    }

    public function pembayaran(): HasMany
    {
        return $this->hasMany(PembayaranSantri::class, 'id_santri', 'id');
    }

    public function dompet(): HasOne
    {
        return $this->hasOne(DompetSantri::class, 'id_santri', 'id');
    }

    public function tabungan(): HasOne
    {
        return $this->hasOne(TabunganSantri::class, 'id_santri', 'id');
    }

    public function prabayarSpp(): HasOne
    {
        return $this->hasOne(PrabayarSpp::class, 'id_santri', 'id');
    }
}
