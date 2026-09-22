<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Peringkat rantai persetujuan Pengajuan Pembayaran. PK = peringkat (Int
 * natural, 1 = tertinggi), bukan autoincrement.
 *
 * Peringkat hanyalah URUTAN. Wewenangnya ada pada empat tanda peran di bawah —
 * dulu dipaku pada angkanya ("hanya peringkat 4 yang boleh mengajukan"), yang
 * membuat level kelima bisa dibuat tetapi tak pernah berfungsi.
 */
class LevelPengajuan extends Model
{
    protected $table = 'level_pengajuan';

    protected $primaryKey = 'peringkat';

    public $incrementing = false;

    protected $keyType = 'int';

    /**
     * Tanda peran → label yang dibaca admin di masternya. Satu daftar dipakai
     * bersama oleh form, validasi, dan halaman daftar; menambah peran berikutnya
     * cukup di sini.
     */
    public const PERAN = [
        'boleh_ajukan_pembayaran' => 'Boleh mengajukan pembayaran',
        'boleh_ajukan_anggaran' => 'Boleh mengajukan anggaran',
        'terikat_bagian' => 'Terikat pada satu Bagian',
        'lingkup_semua' => 'Melihat data seluruh yayasan',
    ];

    /**
     * Bentuk KLASIK empat level bawaan — dipakai seeder & fixture test supaya
     * "pesantren yang belum menyentuh masternya" hanya punya satu rumusan.
     *
     * Peringkat di luar 1–4 tak punya bawaan: level yang dibuat sendiri adalah
     * anak tangga penyetuju biasa sampai wewenangnya dinyalakan dengan sengaja.
     *
     * @return array<string,bool>
     */
    public static function peranBawaan(int $peringkat): array
    {
        return match ($peringkat) {
            1 => ['lingkup_semua' => true],
            3 => ['boleh_ajukan_anggaran' => true, 'terikat_bagian' => true],
            4 => ['boleh_ajukan_pembayaran' => true, 'boleh_ajukan_anggaran' => true, 'terikat_bagian' => true],
            default => [],
        };
    }

    /** Peringkat yang memegang sebuah peran (hanya yang aktif). @return list<int> */
    public static function berperan(string $peran): array
    {
        return static::where('status', 'aktif')->where($peran, true)
            ->orderBy('peringkat')->pluck('peringkat')->all();
    }

    /** Kolom tanda peran. @return list<string> */
    public static function kolomPeran(): array
    {
        return array_keys(self::PERAN);
    }

    /** Sejajar dengan PERAN di atas — tambahkan di kedua tempat bila bertambah. */
    protected $fillable = [
        'peringkat',
        'nama',
        'keterangan',
        'status',
        'boleh_ajukan_pembayaran',
        'boleh_ajukan_anggaran',
        'terikat_bagian',
        'lingkup_semua',
    ];

    protected function casts(): array
    {
        return array_fill_keys(self::kolomPeran(), 'boolean');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'peringkat_pengajuan', 'peringkat');
    }
}
