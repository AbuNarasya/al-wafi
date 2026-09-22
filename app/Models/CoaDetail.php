<?php

namespace App\Models;

use App\Support\Audit\MencatatJejak;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Akun detail (level 4). jenis_saldo (debet/kredit) = sisi normal akun.
 */
class CoaDetail extends Model
{
    use MencatatJejak;

    /** Kode modul hak akses — penyaring di layar Jejak Audit. */
    protected $jejakModul = 'coa-detail';

    protected $table = 'coa_detail';

    protected $primaryKey = 'kode_coa';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'kode_coa',
        'nama_coa',
        'kode_grup',
        'jenis_saldo',
        // operasi | investasi | pendanaan — kelompok akun ini di Laporan Arus
        // Kas. Kosong = belum ditentukan; laporannya menampilkannya terpisah,
        // bukan menebak.
        'klasifikasi_arus_kas',
        // tanpa_pembatasan | dengan_pembatasan — kolom mana akun ini masuk pada
        // Laporan Perubahan Aset Neto. Hanya bermakna untuk Pendapatan (4) &
        // Ekuitas/Aset Neto (3).
        'sifat_pembatasan',
        'status',
        'keterangan',
    ];

    /**
     * Klasifikasi arus kas bawaan saat akun BARU dibuat tanpa menyebutnya.
     *
     * Aturannya sama persis dengan yang dipakai migrasi pengisian awal, dan
     * sengaja ditaruh di sini juga: migrasi hanya menyentuh akun yang sudah
     * ada saat itu, sehingga setiap akun yang dibuat sesudahnya akan lahir
     * tanpa klasifikasi dan diam-diam menumpuk di kelompok "Belum
     * Diklasifikasikan" — persis masalah yang hendak dihindari.
     *
     * Pendapatan & beban → operasi. Ekuitas → pendanaan. Aset & liabilitas
     * DIBIARKAN kosong: piutang itu operasi, aset tetap itu investasi,
     * pinjaman itu pendanaan, dan tak ada cara menebaknya dari nomor akun.
     */
    protected static function booted(): void
    {
        static::creating(function (self $akun) {
            if ($akun->klasifikasi_arus_kas !== null) {
                return;
            }
            $akun->klasifikasi_arus_kas = match (self::akarKelompok($akun->kode_grup)) {
                '4', '5' => 'operasi',
                '3' => 'pendanaan',
                default => null,
            };
        });

        // Sifat pembatasan bawaan — alasan yang sama: migrasi hanya menyentuh
        // akun yang ada saat itu. Akun Pendapatan & Aset Neto lahir TANPA
        // pembatasan; yang terikat ditandai sendiri oleh penggunanya.
        static::creating(function (self $akun) {
            if ($akun->sifat_pembatasan !== null) {
                return;
            }
            if (in_array(self::akarKelompok($akun->kode_grup), ['3', '4'], true)) {
                $akun->sifat_pembatasan = 'tanpa_pembatasan';
            }
        });
    }

    /**
     * Peta `kode_grup => akar kelompok (1..5)` untuk SELURUH grup, satu kueri.
     *
     * `akarKelompok()` menelusuri ke atas dengan satu `find()` per tingkat, dan
     * itu memadai untuk satu-dua akun. Untuk seluruh daftar akun ia berubah jadi
     * ratusan kueri: sebuah layar berisi 109 akun pernah menghabiskan 461 kueri,
     * yang di laptop tak terasa (Postgres sebelah) tetapi dari Hostinger ke Neon
     * Singapura menjadi belasan detik — dan halamannya kehabisan waktu, bukan
     * melambat. Pakai peta ini kapan pun akar dibutuhkan untuk BANYAK akun.
     *
     * Sengaja TIDAK disimpan di variabel statis: cache statis hidup melewati
     * batas test dalam satu proses, dan grup yang dibuat fixture sesudahnya tak
     * akan pernah terlihat.
     *
     * @return array<string,string>
     */
    public static function petaAkarKelompok(): array
    {
        $induk = CoaGroup::pluck('kode_induk', 'kode_grup')->all();

        // Pemaksaan ke string BUKAN kerapian: PHP mengubah kunci larik yang
        // berupa angka menjadi integer, sehingga `array_keys()` mengembalikan
        // int 1 untuk grup bernama "1" — dan pemanggilnya membandingkan hasil
        // ini secara KETAT dengan ['1','2','3']. Tanpa pemaksaan ini, akun yang
        // menggantung langsung pada kelompok utama hilang dari daftar tanpa satu
        // pun galat, sementara akun di grup bersarang tetap muncul.
        $peta = [];
        foreach (array_keys($induk) as $kode) {
            $cur = (string) $kode;
            $lihat = [];
            while (! empty($induk[$cur]) && ! isset($lihat[$cur])) {
                $lihat[$cur] = true;
                $cur = (string) $induk[$cur];
            }
            $peta[(string) $kode] = $cur;
        }

        return $peta;
    }

    /** Telusuri grup ke atas sampai akar kelompoknya (1..5). */
    public static function akarKelompok(?string $kodeGrup): ?string
    {
        $cur = $kodeGrup ? CoaGroup::find($kodeGrup) : null;
        $lihat = [];
        while ($cur && $cur->kode_induk && ! in_array($cur->kode_grup, $lihat, true)) {
            $lihat[] = $cur->kode_grup;
            $cur = CoaGroup::find($cur->kode_induk);
        }

        return $cur?->kode_grup;
    }

    public function grup(): BelongsTo
    {
        return $this->belongsTo(CoaGroup::class, 'kode_grup', 'kode_grup');
    }

    public function bankAccount(): HasOne
    {
        return $this->hasOne(BankAccount::class, 'kode_coa', 'kode_coa');
    }
}
