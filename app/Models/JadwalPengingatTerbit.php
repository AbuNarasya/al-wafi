<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Jadwal berulang yang menepuk bahu petugas agar menyusun & memeriksa draft. */
class JadwalPengingatTerbit extends Model
{
    protected $table = 'jadwal_pengingat_terbit';

    protected $guarded = ['id'];

    /** Tanggal 0 = hari terakhir bulan; lihat catatan di migrasinya. */
    public const AKHIR_BULAN = 0;

    protected function casts(): array
    {
        return [
            'aktif' => 'boolean',
            'tanggal' => 'integer',
            'bulan' => 'integer',
            'hari_sebelum' => 'integer',
        ];
    }

    public function jenis(): BelongsTo
    {
        return $this->belongsTo(JenisBiaya::class, 'kode_jenis', 'kode');
    }

    public function konfirmasi(): HasMany
    {
        return $this->hasMany(KonfirmasiPengingatTerbit::class, 'id_jadwal', 'id');
    }

    public function labelModul(): string
    {
        return BatchTagihan::MODUL[$this->modul] ?? $this->modul;
    }

    /** Bunyi jadwalnya dalam satu kalimat, untuk layar & untuk isi notifikasi. */
    public function labelIrama(): string
    {
        $hari = $this->tanggal === self::AKHIR_BULAN
            ? 'hari terakhir bulan'
            : "tanggal {$this->tanggal}";

        $inti = $this->irama === 'tahunan'
            ? 'tiap tahun, '.($this->bulan ? \Illuminate\Support\Carbon::create(null, $this->bulan, 1)->translatedFormat('F') : '').' '.$hari
            : "tiap bulan, {$hari}";

        return $this->hari_sebelum > 0 ? "{$inti} (diingatkan {$this->hari_sebelum} hari sebelumnya)" : $inti;
    }
}
