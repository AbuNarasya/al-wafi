<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Master Tipe Biaya. `perilaku` menentukan ALUR yang diikuti tipe ini
 * (registrasi | uang_pangkal | spp | lain) — kode boleh apa saja, tetapi
 * program selalu menyaring berdasarkan perilakunya.
 */
class TipeBiaya extends Model
{
    protected $table = 'tipe_biaya';

    protected $primaryKey = 'kode';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /** Perilaku yang dikenal program; tak bisa ditambah lewat master. */
    public const PERILAKU = [
        'registrasi' => 'Registrasi — tagihan terbit otomatis saat calon mendaftar',
        'uang_pangkal' => 'Uang Pangkal — potongan gelombang & angsuran termin',
        'spp' => 'SPP — terbit berkala per periode',
        'lain' => 'Lain-lain — ditagihkan manual per santri',
    ];

    protected function casts(): array
    {
        return ['bawaan' => 'boolean'];
    }

    /** @var array<string,list<string>>|null memo per request */
    private static ?array $memoKode = null;

    /**
     * Kode tipe yang berperilaku tertentu — pengganti daftar nama yang dulu
     * dipaku di kode (`whereIn('tipe', ['registrasi','uang_pangkal'])`).
     *
     * Selalu memuat nama perilaku itu sendiri walau masternya kosong/terhapus,
     * supaya data lama (yang tipenya persis bernama perilaku) tetap terbaca.
     *
     * @return list<string>
     */
    public static function kode(string ...$perilaku): array
    {
        self::$memoKode ??= static::query()->get(['kode', 'perilaku'])
            ->groupBy('perilaku')->map(fn ($g) => $g->pluck('kode')->all())->all();

        $hasil = [];
        foreach ($perilaku as $p) {
            $hasil = array_merge($hasil, self::$memoKode[$p] ?? [], [$p]);
        }

        return array_values(array_unique($hasil));
    }

    /** Perilaku sebuah kode tipe; kode tak dikenal dianggap berperilaku sama dengan namanya. */
    public static function perilakuDari(?string $kode): ?string
    {
        if ($kode === null) {
            return null;
        }
        foreach (['registrasi', 'uang_pangkal', 'spp', 'lain'] as $p) {
            if (in_array($kode, self::kode($p), true)) {
                return $p;
            }
        }

        return null;
    }

    /** Opsi dropdown [kode => nama] untuk tipe aktif. */
    public static function opsi(): array
    {
        return static::where('status', 'aktif')->orderBy('urutan')->orderBy('kode')
            ->pluck('nama', 'kode')->all();
    }

    /** Buang memo (dipakai test yang menambah tipe di tengah jalan). */
    public static function lupakan(): void
    {
        self::$memoKode = null;
    }
}
