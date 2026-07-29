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

    protected $fillable = ['kode', 'nama', 'jumlah_tingkat', 'urutan', 'status', 'keterangan'];

    protected function casts(): array
    {
        return ['urutan' => 'integer', 'jumlah_tingkat' => 'integer'];
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
        for ($i = 1; $i <= (int) $this->jumlah_tingkat; $i++) {
            $hasil[$i] = "Tingkat {$i}";
        }

        return $hasil;
    }

    /** Peta [kode jenjang => jumlah tingkat] untuk jenjang aktif — bahan dropdown bertingkat. */
    public static function petaTingkat(): array
    {
        return static::where('status', 'aktif')->orderBy('urutan')->orderBy('kode')
            ->pluck('jumlah_tingkat', 'kode')->map(fn ($n) => (int) $n)->all();
    }
}
