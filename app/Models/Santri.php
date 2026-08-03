<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Status yang termasuk "calon" (lingkup PPSB).
     *
     * `mengundurkan_diri` sengaja TIDAK di sini — ia berdaftar sendiri, sama
     * seperti alumni & santri keluar. Calon yang mundur sudah selesai urusannya
     * (tagihannya pun ditutup saat ia mundur), jadi membiarkannya berbaur di
     * daftar kerja PPSB hanya menaikkan angka pendaftar yang tak pernah datang.
     */
    public const CALON = ['calon', 'terbayar', 'terverifikasi', 'diseleksi', 'diterima', 'lolos_kesehatan', 'tidak_lulus', 'gagal_medcheck'];

    /**
     * Status per daftar. ENAM daftar terpisah, bukan satu daftar bercampur:
     * alumni & santri keluar dulu ikut nongol di daftar Santri Kependidikan,
     * sehingga jumlah "santri" di layar tak pernah sama dengan jumlah santri yang
     * benar-benar bersekolah. Pemisahannya murni tampilan — barisnya tetap satu
     * tabel, jadi tagihan bersisa milik alumni tetap bisa ditagih.
     */
    public const LINGKUP = [
        'calon' => self::CALON,
        'siap_aktivasi' => ['siap_aktivasi'],
        'aktif' => ['aktif'],
        'alumni' => ['alumni'],
        'keluar' => ['keluar'],
        'mundur' => ['mengundurkan_diri'],
    ];

    /**
     * Saring menurut DAFTAR tempat santri ditampilkan. Ditaruh di model, bukan
     * di controller, karena dipakai dua tempat yang isinya HARUS sama: daftar di
     * layar dan berkas unduhannya. Lingkup tak dikenal jatuh ke `aktif`.
     */
    public function scopeLingkup(Builder $query, string $lingkup): Builder
    {
        $status = self::LINGKUP[$lingkup] ?? self::LINGKUP['aktif'];

        // Daftar CALON juga memuat santri yang sedang MELANJUTKAN ke jenjang
        // berikutnya. Statusnya sengaja tetap `aktif` (ia masih bersekolah &
        // masih ditagih SPP sampai kenaikannya dieksekusi), jadi tanpa baris
        // ini pekerjaan PPSB atas mereka — seleksi, med check — tak pernah
        // muncul di daftar tempat PPSB bekerja.
        return $lingkup === 'calon'
            ? $query->where(fn ($w) => $w
                ->whereIn('status', $status)
                ->orWhereHas('pendaftaranSemua', fn ($p) => $p->lanjutan()->terbuka()))
            : $query->whereIn('status', $status);
    }

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
            'tanggal_lulus' => 'date',
            'gelombang' => 'integer',
            'tingkat' => 'integer',
            'nominal_spp' => 'decimal:2',
        ];
    }

    public function wali(): BelongsTo
    {
        return $this->belongsTo(Wali::class, 'id_wali', 'id');
    }

    /**
     * Siklus pendaftaran TERBARU. Sejak kenaikan jenjang internal juga melewati
     * PPSB, satu santri bisa punya beberapa baris pendaftaran — jadi relasi ini
     * harus menyebut yang mana. Pemakai lama (form berkas, seleksi, med check
     * pendaftar baru) tetap mendapat baris yang benar karena siklus terbaru
     * memang yang sedang dikerjakan.
     */
    public function pendaftaran(): HasOne
    {
        return $this->hasOne(Pendaftaran::class, 'id_santri', 'id')->latestOfMany();
    }

    /** Seluruh siklus pendaftaran, terbaru lebih dulu (riwayat kenaikan jenjang). */
    public function pendaftaranSemua(): HasMany
    {
        return $this->hasMany(Pendaftaran::class, 'id_santri', 'id')->orderByDesc('id');
    }

    /** Di mana santri ini berada tiap tahun ajaran. */
    public function riwayatTingkat(): HasMany
    {
        return $this->hasMany(RiwayatTingkat::class, 'id_santri', 'id')->orderBy('tahun_ajaran');
    }

    /**
     * Tahun ajaran yang SEDANG DIJALANI — bukan angkatan. Kolomnya baru, jadi
     * data lama yang belum terisi jatuh ke tahun masuknya, dan itu memang benar
     * bagi santri yang belum pernah naik.
     */
    public function taBerjalan(): ?string
    {
        return $this->tahun_ajaran_berjalan ?: $this->tahun_ajaran;
    }

    /** Jalur pendaftaran (reguler/pindahan/anak karyawan/…) — kolom `jalur` menyimpan kodenya. */
    public function jalurPendaftaran(): BelongsTo
    {
        return $this->belongsTo(JalurPendaftaran::class, 'jalur', 'kode');
    }

    /**
     * Jenjangnya. Ada supaya LAYAR bisa menyebut NAMA jenjang, bukan kode
     * `J001` — sejak kode berformat itu, ia tak lagi bercerita apa pun bagi
     * pembaca. Untuk daftar, muat lewat `with('jenjang')` agar tak jadi N+1.
     */
    public function jenjang(): BelongsTo
    {
        return $this->belongsTo(Jenjang::class, 'kode_jenjang', 'kode');
    }

    public function dokumen(): HasMany
    {
        return $this->hasMany(DokumenSantri::class, 'id_santri', 'id');
    }

    public function tagihan(): HasMany
    {
        return $this->hasMany(TagihanSantri::class, 'id_santri', 'id');
    }

    /**
     * Seluruh NIS yang pernah dipegang santri ini — nomornya diterbitkan ulang
     * setiap kali ia masuk jenjang baru. `nis` di tabel ini memegang yang
     * BERLAKU; riwayatnya dipakai agar nomor lama di kartu & rapor tetap bisa
     * dicari.
     */
    public function riwayatNis(): HasMany
    {
        return $this->hasMany(NisSantri::class, 'id_santri', 'id');
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
