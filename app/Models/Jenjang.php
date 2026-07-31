<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Master jenjang pendidikan. `kode` dirujuk sebagai string oleh tabel lain. */
class Jenjang extends Model
{
    protected $table = 'jenjang';

    protected $primaryKey = 'kode';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['kode', 'nama', 'jumlah_tingkat', 'tingkat_mulai', 'kode_jenjang_lanjutan', 'urutan', 'status', 'keterangan'];

    protected function casts(): array
    {
        return ['urutan' => 'integer', 'jumlah_tingkat' => 'integer', 'tingkat_mulai' => 'integer'];
    }

    /**
     * Nomor tingkat PERTAMA jenjang ini. SDTQ 1, SMP 7, SMA 10 — penomorannya
     * berkelanjutan antar jenjang supaya "Tingkat 8" hanya punya satu arti.
     *
     * Null dianggap 1: baris master lama belum mengisinya, dan jenjang yang
     * berdiri sendiri memang mulai dari 1.
     */
    public function tingkatMulai(): int
    {
        return (int) ($this->tingkat_mulai ?: 1);
    }

    /** Nomor tingkat TERAKHIR jenjang ini (0 bila jumlah tingkatnya belum diisi). */
    public function tingkatAkhir(): int
    {
        return (int) $this->jumlah_tingkat ? $this->tingkatMulai() + (int) $this->jumlah_tingkat - 1 : 0;
    }

    /** Jenjang berikutnya; null = jenjang terakhir (santrinya menjadi alumni). */
    public function lanjutan(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(self::class, 'kode_jenjang_lanjutan', 'kode');
    }

    /**
     * Pilihan tingkat jenjang ini: [1 => 'Tingkat 1', …]. Kosong bila jumlah
     * tingkatnya belum diisi di master — form akan memintanya diisi dulu
     * daripada menawarkan daftar kosong tanpa penjelasan.
     *
     * @return array<int,string>
     */
    public function opsiTingkat(): array
    {
        $hasil = [];
        for ($i = $this->tingkatMulai(); $i <= $this->tingkatAkhir(); $i++) {
            $hasil[$i] = "Tingkat {$i}";
        }

        return $hasil;
    }

    /**
     * Peta [kode jenjang => [mulai, akhir]] untuk jenjang aktif — bahan dropdown
     * bertingkat di sisi Alpine.
     *
     * Dulu hanya JUMLAH tingkatnya, dan layar merakit pilihan 1..jumlah. Sejak
     * penomorannya berkelanjutan, jumlah saja tak cukup: SMP bertingkat 3 tetapi
     * pilihannya 7–9, bukan 1–3.
     *
     * @return array<string,array{mulai:int,akhir:int}>
     */
    public static function petaTingkat(): array
    {
        return static::where('status', 'aktif')->orderBy('urutan')->orderBy('kode')
            ->get(['kode', 'jumlah_tingkat', 'tingkat_mulai'])
            ->mapWithKeys(fn ($j) => [$j->kode => ['mulai' => $j->tingkatMulai(), 'akhir' => $j->tingkatAkhir()]])
            ->all();
    }
}
