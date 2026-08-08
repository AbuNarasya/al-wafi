<?php

namespace App\Services\Impor\Pemeta;

use App\Models\JalurPendaftaran;
use App\Models\JenisBiaya;
use App\Models\Jenjang;
use App\Models\RiwayatTingkat;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\TahunAjaran;
use App\Models\TipeBiaya;
use App\Models\Wali;
use App\Services\Impor\BantuanPemeta;
use App\Services\Impor\Pemeta;
use App\Support\Money;

/**
 * SANTRI LAMA — santri yang sudah bersekolah sebelum aplikasi ini dipakai.
 *
 * SENGAJA TIDAK lewat jalur PPSB. Pendaftaran biasa menerbitkan tagihan
 * registrasi otomatis, dan memajukan status hanya bisa dengan memverifikasi
 * pembayaran registrasi — yang MENERBITKAN JURNAL. Memaksakan jalur itu untuk
 * santri lama berarti mengarang uang masuk dan pendapatan yang tak pernah ada.
 * Karena itu di sini santri ditulis langsung berstatus "aktif", memakai NIS
 * aslinya, tanpa baris pendaftaran dan tanpa tagihan registrasi.
 *
 * Tunggakannya dibuat sebagai tagihan ber-`sudah_akrual = true` dan TANPA
 * jurnal: nilainya sudah diakui sebagai pendapatan di catatan lama. Penanda itu
 * membuat pembayaran nanti mengkredit PIUTANG, bukan Pendapatan — kalau tidak,
 * pendapatannya tercatat dua kali. Total piutangnya masuk sekali lewat jurnal
 * pembuka (menu Saldo Awal), bukan dari sini.
 */
class PemetaSantriLama implements Pemeta
{
    use BantuanPemeta;

    /**
     * Jenis tunggakan yang bisa dibawa santri lama, mengikuti daftar Tipe Biaya.
     * Kunci menentukan nama kolom (`tunggakan_{kunci}`, `ket_tunggakan_{kunci}`)
     * dan nama parameternya (`jenis_tunggakan_{kunci}`) — ditulis sekali di sini
     * supaya menambah jenis berikutnya tak perlu menyunting tiga tempat.
     *
     * Registrasi sengaja TIDAK ada: biaya pendaftaran dibayar di awal, tak
     * mungkin menjadi tunggakan santri yang sudah bersekolah.
     */
    private const TUNGGAKAN = [
        'spp' => [
            'label' => 'SPP',
            'contoh' => '1500000',
            'contoh_ket' => 'Tunggakan SPP Jan-Jun 2026',
            'bawaan_ket' => 'Tunggakan SPP',
        ],
        'uang_pangkal' => [
            'label' => 'Uang Pangkal',
            'contoh' => '5000000',
            'contoh_ket' => 'Sisa uang pangkal angkatan 2023',
            'bawaan_ket' => 'Sisa uang pangkal',
        ],
        'daftar_ulang' => [
            'label' => 'Daftar Ulang',
            'contoh' => '750000',
            'contoh_ket' => 'Daftar ulang T.A 2026/2027',
            'bawaan_ket' => 'Tunggakan daftar ulang',
        ],
        'lain' => [
            'label' => 'Tagihan Lain',
            'contoh' => '300000',
            'contoh_ket' => 'Seragam & buku',
            'bawaan_ket' => 'Tunggakan lain-lain',
        ],
    ];

    public static function kunci(): string
    {
        return 'santri-lama';
    }

    public static function judul(): string
    {
        return 'Santri Lama';
    }

    public static function penjelasan(): string
    {
        return 'Santri yang sudah bersekolah sebelum aplikasi ini dipakai. Ditulis langsung '
            .'berstatus aktif dengan NIS aslinya — tanpa tagihan registrasi dan tanpa jurnal. '
            .'Tunggakannya ikut dibuat bila kolomnya diisi: SPP, uang pangkal, daftar ulang, '
            .'dan tagihan lain — satu tagihan per jenis, jadi pelunasannya bisa dipisah.';
    }

    public function kolom(): array
    {
        $kolom = [
            'nis' => ['wajib' => true, 'contoh' => '230015', 'ket' => 'NIS asli santri. Harus unik.'],
            'nama' => ['wajib' => true, 'contoh' => 'Ahmad Fauzi', 'ket' => 'Nama lengkap.'],
            'jenis_kelamin' => ['wajib' => true, 'contoh' => 'L', 'ket' => 'L atau P.'],
            'kode_jenjang' => [
                'wajib' => true,
                'contoh' => Jenjang::orderBy('urutan')->orderBy('kode')->value('nama') ?? 'SMP',
                'ket' => 'Boleh diisi KODE (mis. J002) atau NAMA jenjangnya (mis. SMP) — keduanya diterima.',
            ],
            // Tingkat WAJIB: tanpa itu proses kenaikan tahun depan tak tahu harus
            // menaikkan dari mana, dan ratusan santri harus diisi satu per satu.
            'tingkat' => ['wajib' => true, 'contoh' => '2', 'ket' => 'Tingkat/kelas yang sedang dijalani. Harus dalam jangkauan jenjangnya (lihat Jumlah Tingkat di master Jenjang).'],
            'tahun_ajaran' => ['wajib' => true, 'contoh' => '2026/2027', 'ket' => 'T.A yang sedang dijalani.'],
            'jalur' => ['wajib' => true, 'contoh' => 'LAMA', 'ket' => 'Kode jalur dari master. Disarankan jalur khusus "Santri Lama".'],
            'wali_nama' => ['wajib' => true, 'contoh' => 'Bapak Fauzi', 'ket' => 'Dibuat otomatis bila belum ada.'],
            'wali_telepon' => ['wajib' => true, 'contoh' => '08123456789', 'ket' => 'Kunci pengait: telepon sama = wali yang sama, jadi kakak-beradik menempel ke satu wali.'],
            // TIDAK ADA `angkatan`: tabel santri tak punya kolom itu, jadi isinya
            // selalu terbuang. Menawarkannya di templat hanya membuat petugas
            // mengisi kolom yang tak pernah sampai ke mana-mana.
            // Tahun masuk sudah terwakili `tahun_ajaran`.
            'tempat_lahir' => ['wajib' => false, 'contoh' => 'Bogor', 'ket' => ''],
            'tanggal_lahir' => ['wajib' => false, 'contoh' => '2011-05-17', 'ket' => 'Format YYYY-MM-DD.'],
            'nisn' => ['wajib' => false, 'contoh' => '0071234567', 'ket' => ''],
        ];

        // Empat pasang kolom tunggakan — semuanya opsional; yang kosong/0 tidak
        // menerbitkan tagihan apa pun.
        foreach (self::TUNGGAKAN as $k => $t) {
            $kolom["tunggakan_{$k}"] = [
                'wajib' => false, 'contoh' => $t['contoh'],
                'ket' => "Sisa tunggakan {$t['label']} yang belum dibayar. Kosong atau 0 = tidak ada.",
            ];
            $kolom["ket_tunggakan_{$k}"] = [
                'wajib' => false, 'contoh' => $t['contoh_ket'],
                'ket' => 'Keterangan yang tampil di tagihan.',
            ];
        }

        return $kolom;
    }

    public function parameter(): array
    {
        // Syarat mutlak: jenis biayanya WAJIB punya akun piutang, karena itulah
        // yang dikredit saat wali membayar tunggakan. Perilakunya sendiri tidak
        // dibatasi — daftar Tipe Biaya tiap pesantren berbeda, dan memaksa
        // "harus berperilaku lain" membuat tunggakan SPP/uang pangkal tak bisa
        // ditunjuk ke jenis biayanya yang sebenarnya. Konsekuensi memilih jenis
        // yang MASIH BERJALAN disebutkan di keterangan tiap isian.
        $opsi = JenisBiaya::whereNotNull('kode_coa_piutang')
            ->where('status', 'aktif')->orderBy('kode')
            ->with('jenjang')->get()->mapWithKeys(fn ($j) => [
                // Jenjangnya disebut lewat NAMA, bukan kodenya: sejak kode berformat
                // J001 ia tak lagi bercerita apa-apa bagi pembaca daftar.
                $j->kode => "{$j->kode} — {$j->nama}".($j->kode_jenjang ? ' ('.($j->jenjang->nama ?? $j->kode_jenjang).')' : ''),
            ])->all();

        $catatan = [
            'spp' => 'Bila ditunjuk ke jenis SPP yang berjalan, tagihan ini tetap TIDAK mengganggu penerbitan SPP bulanan (penjaganya per periode, dan tagihan tunggakan tak berperiode) — hanya saja di laporan keduanya menyatu.',
            'uang_pangkal' => 'Bila ditunjuk ke jenis uang pangkal yang berjalan, tagihan ini ikut muncul di modul Angsuran Uang Pangkal (bisa dibuatkan termin) dan di kartu penerimaan uang pangkal Dashboard PPSB.',
            'daftar_ulang' => '',
            'lain' => '',
        ];

        $hasil = [];
        foreach (self::TUNGGAKAN as $k => $t) {
            $hasil["jenis_tunggakan_{$k}"] = [
                'label' => "Jenis biaya untuk tunggakan {$t['label']}",
                'tipe' => 'pilih',
                'opsi' => $opsi,
                'ket' => trim("Wajib dipilih bila kolom tunggakan_{$k} ada isinya. ".($catatan[$k] ?? '')),
            ];
        }

        return $hasil;
    }

    public function periksaParameter(array $param): ?string
    {
        // Jenis biaya tunggakan boleh kosong (berkas tanpa tunggakan), tetapi
        // kalau diisi harus benar — memeriksanya di sini menghindari pesan yang
        // sama berulang di ratusan baris.
        $terpakai = [];
        foreach (array_keys(self::TUNGGAKAN) as $k) {
            $kode = trim($param["jenis_tunggakan_{$k}"] ?? '');
            if ($salah = $this->periksaJenis($kode)) {
                return $salah;
            }
            if ($kode === '') {
                continue;
            }
            // Dua kolom tunggakan yang menunjuk jenis biaya SEKALI-PER-TAHUN yang
            // sama akan melanggar indeks unik anti tagih-ganda begitu keduanya
            // terisi pada satu santri. Ditolak di sini supaya pesannya menuntun,
            // bukan muncul sebagai galat basis data di tengah impor.
            $perilaku = TipeBiaya::perilakuDari($this->jenisBiaya($kode)?->tipe);
            if ($perilaku === null || $perilaku === 'lain') {
                continue;
            }
            if (isset($terpakai[$kode])) {
                return "Kolom tunggakan \"{$terpakai[$kode]}\" dan \"{$k}\" sama-sama menunjuk jenis biaya \"{$kode}\". "
                    .'Jenis berperilaku '.$perilaku.' hanya boleh satu tagihan per santri per tahun ajaran — '
                    .'pilih jenis yang berbeda, atau pakai jenis berperilaku Lain-lain untuk salah satunya.';
            }
            $terpakai[$kode] = $k;
        }

        return null;
    }

    /*
     |--------------------------------------------------------------------------
     | SIMPANAN SEKALI-BACA
     |--------------------------------------------------------------------------
     | `periksa()` dipanggil SEKALI PER BARIS, dan tiap panggilan dulu menembak
     | database sendiri-sendiri: NIS, jenjang (dua kueri — kode lalu nama), tahun
     | ajaran, jalur, wali, ditambah satu kueri per kolom tunggakan yang terisi.
     | Untuk berkas 202 santri itu ±1.200 kueri berurutan.
     |
     | Di mesin pengembang tak terasa: PostgreSQL-nya di komputer yang sama,
     | ±0,1 ms sekali jalan. Di produksi database ada di Neon, belasan milidetik
     | sekali jalan — dan 1.200 kali belasan milidetik itulah yang membuat
     | pratinjau impor berakhir 502: permintaannya kelewat panjang, `artisan
     | serve` yang satu proses ikut terblokir, health check Render diam, dan
     | container-nya direstart di tengah jalan.
     |
     | Semua yang ditanyakan berulang itu MASTER yang tak berubah selama impor
     | berjalan, jadi cukup dibaca sekali lalu disimpan di instance ini —
     | pemetanya memang dibuat sekali per impor. Menukar sedikit memori dengan
     | seribu perjalanan bolak-balik ke seberang jaringan.
     |
     | Yang TIDAK boleh ikut disimpan: apa pun yang ditulis `simpan()` sendiri.
     | Wali karena itu punya penanganan khusus — lihat catatannya di sana.
     */

    /** @var array<string,Jenjang>|null kode & nama (huruf kecil) → Jenjang */
    private ?array $petaJenjang = null;

    /** @var array<string,true>|null */
    private ?array $kodeTahunAjaran = null;

    /** @var array<string,true>|null */
    private ?array $kodeJalur = null;

    /** @var array<string,JenisBiaya|null> */
    private array $petaJenis = [];

    /** @var array<string,true>|null NIS yang sudah ada di tabel santri */
    private ?array $nisTerpakai = null;

    /** @var array<string,Wali>|null telepon → wali */
    private ?array $waliPerTelepon = null;

    /**
     * Jenjang dicari lewat KODE dulu, lalu NAMA.
     *
     * Sejak kode jenjang berformat `J001`, angka itu tak bisa ditebak penyusun
     * berkas — sedangkan berkas pindahan biasanya sudah menuliskan "SDTQ"/"SMP".
     * Menerima keduanya membuat berkas lama tetap terpakai tanpa ditulis ulang.
     *
     * Pencocokannya kini tak lagi peka huruf besar-kecil untuk KODE juga, karena
     * keduanya dikunci dalam huruf kecil. Itu melonggarkan, bukan mengetatkan:
     * berkas yang menulis `j001` dulu ditolak, sekarang diterima.
     */
    private function cariJenjang(string $kodeAtauNama): ?Jenjang
    {
        if ($kodeAtauNama === '') {
            return null;
        }

        if ($this->petaJenjang === null) {
            $this->petaJenjang = [];
            foreach (Jenjang::all() as $j) {
                // Nama lebih dulu, kode sesudahnya — bila sebuah nama kebetulan
                // sama dengan kode jenjang lain, kodelah yang menang.
                $this->petaJenjang[mb_strtolower((string) $j->nama)] = $j;
                $this->petaJenjang[mb_strtolower((string) $j->kode)] = $j;
            }
        }

        return $this->petaJenjang[mb_strtolower($kodeAtauNama)] ?? null;
    }

    private function tahunAjaranAda(string $kode): bool
    {
        $this->kodeTahunAjaran ??= TahunAjaran::pluck('kode')->flip()->all();

        return isset($this->kodeTahunAjaran[$kode]);
    }

    private function jalurAda(string $kode): bool
    {
        $this->kodeJalur ??= JalurPendaftaran::pluck('kode')->flip()->all();

        return isset($this->kodeJalur[$kode]);
    }

    private function nisSudahAda(string $nis): bool
    {
        $this->nisTerpakai ??= Santri::whereNotNull('nis')->pluck('nis')->flip()->all();

        return isset($this->nisTerpakai[$nis]);
    }

    private function waliBerTelepon(string $telepon): ?Wali
    {
        $this->waliPerTelepon ??= Wali::get(['id', 'nama', 'telepon'])->keyBy('telepon')->all();

        return $this->waliPerTelepon[$telepon] ?? null;
    }

    /** Jenis biaya tunggakan sah? Kosong dianggap sah (berkas boleh tanpa tunggakan). */
    private function periksaJenis(string $kode): ?string
    {
        if ($kode === '') {
            return null;
        }
        $jenis = $this->jenisBiaya($kode);
        if (! $jenis) {
            return "Jenis biaya \"{$kode}\" tidak ditemukan.";
        }
        if (! $jenis->kode_coa_piutang) {
            return "Jenis biaya \"{$kode}\" belum punya akun piutang — lengkapi dulu di master Jenis Biaya.";
        }

        return null;
    }

    /** Disimpan PER KODE, bukan seluruh tabel: yang ditanya paling banyak empat. */
    private function jenisBiaya(string $kode): ?JenisBiaya
    {
        return $this->petaJenis[$kode] ??= JenisBiaya::whereKey($kode)->first();
    }

    /**
     * NIS berindeks unik di tabel santri. Dua baris ber-NIS sama membuat
     * seluruh impor batal di tengah jalan dengan galat SQL yang tak menyebut
     * baris mana pun — pernah terjadi pada berkas SDTQ berisi kakak-beradik
     * yang NIS-nya salah ketik jadi sama.
     *
     * NISN TIDAK ikut: kolomnya memang tak berindeks unik, dan berkas pindahan
     * kerap membiarkannya kosong atau sementara diisi seadanya.
     */
    public function kolomUnik(): array
    {
        return ['nis'];
    }

    public function periksa(array $baris, array $param): array
    {
        $nis = trim($baris['nis'] ?? '');
        if ($nis === '') {
            return $this->masalah('NIS kosong.');
        }
        if ($this->nisSudahAda($nis)) {
            return $this->lewati(); // sudah pernah diimpor
        }
        if (trim($baris['nama'] ?? '') === '') {
            return $this->masalah('Nama kosong.');
        }
        if (! in_array(strtoupper(trim($baris['jenis_kelamin'] ?? '')), ['L', 'P'], true)) {
            return $this->masalah('Jenis kelamin harus L atau P.');
        }

        $kodeJenjang = trim($baris['kode_jenjang'] ?? '');
        $jenjang = $this->cariJenjang($kodeJenjang);
        if (! $jenjang) {
            return $this->masalah("Jenjang \"{$kodeJenjang}\" tidak ada di master Jenjang (boleh diisi kode maupun namanya).");
        }

        // Tingkat dibatasi jenjangnya — aturan yang sama dengan form pendaftaran.
        $tingkat = trim($baris['tingkat'] ?? '');
        if ($tingkat === '' || ! ctype_digit($tingkat)) {
            return $this->masalah('Tingkat kosong atau bukan angka bulat.');
        }
        if (! $jenjang->jumlah_tingkat) {
            return $this->masalah("Jumlah tingkat jenjang \"{$jenjang->nama}\" belum diisi di master Jenjang, jadi tingkat santri tak bisa diperiksa.");
        }
        // Penomoran tingkat berkelanjutan: SMP 7–9, SMA 10–12. Berkas impor
        // WAJIB memakai penomoran itu juga — memaksanya di sini lebih baik
        // daripada menerima "2" lalu diam-diam menaruhnya di kelas yang salah.
        if ((int) $tingkat < $jenjang->tingkatMulai() || (int) $tingkat > $jenjang->tingkatAkhir()) {
            return $this->masalah("Tingkat {$tingkat} tidak ada di jenjang \"{$jenjang->nama}\" "
                ."(hanya tingkat {$jenjang->tingkatMulai()}–{$jenjang->tingkatAkhir()}).");
        }

        $ta = trim($baris['tahun_ajaran'] ?? '');
        if (! $this->tahunAjaranAda($ta)) {
            return $this->masalah("Tahun ajaran \"{$ta}\" tidak ada di master Tahun Ajaran.");
        }

        // Jalur berlaku lintas tahun ajaran, jadi cukup diperiksa keberadaannya.
        $jalur = trim($baris['jalur'] ?? '');
        if (! $this->jalurAda($jalur)) {
            return $this->masalah("Jalur \"{$jalur}\" tidak ada di master Jalur Pendaftaran.");
        }

        $telepon = trim($baris['wali_telepon'] ?? '');
        $namaWali = trim($baris['wali_nama'] ?? '');
        if ($telepon === '' || $namaWali === '') {
            return $this->masalah('Nama atau telepon wali kosong.');
        }
        $waliAda = $this->waliBerTelepon($telepon);
        if ($waliAda && mb_strtolower((string) $waliAda->nama) !== mb_strtolower($namaWali)) {
            return $this->masalah("Telepon {$telepon} sudah dipakai wali bernama \"{$waliAda->nama}\" — periksa apakah datanya keliru.");
        }

        if (($t = trim($baris['tanggal_lahir'] ?? '')) !== '' && ! $this->tanggalSah($t)) {
            return $this->masalah("Tanggal lahir \"{$t}\" tidak terbaca. Pakai format YYYY-MM-DD.");
        }

        foreach (array_keys(self::TUNGGAKAN) as $k) {
            $kolom = "tunggakan_{$k}";
            $nilai = $this->angka($baris[$kolom] ?? '');
            if ($nilai === null) {
                return $this->masalah("Kolom {$kolom} bukan angka yang sah.");
            }
            if (Money::gtZero($nilai)) {
                $kodeJenis = trim($param["jenis_tunggakan_{$k}"] ?? '');
                if ($kodeJenis === '') {
                    return $this->masalah("Ada nilai di {$kolom}, tetapi jenis biayanya belum dipilih di form impor.");
                }
                if ($salah = $this->periksaJenis($kodeJenis)) {
                    return $this->masalah($salah);
                }
            }
        }

        return $this->siap();
    }

    /**
     * Menyimpan BERKELOMPOK, bukan sebaris demi sebaris.
     *
     * Dulu tiap santri berarti 6–9 perjalanan ke database: wali, santri, riwayat
     * tingkat (`updateOrCreate` = SELECT lalu INSERT), riwayat NIS, dan satu per
     * kolom tunggakan. Untuk 201 santri itu ±1.500 perjalanan berurutan di dalam
     * SATU transaksi — dan di produksi, dengan Neon di seberang jaringan, itu
     * puluhan detik. Permintaan sepanjang itu memblokir `artisan serve` yang satu
     * proses, health check Render diam, container direstart, dan penggunanya
     * melihat 503 padahal datanya sudah masuk seluruhnya.
     *
     * Sekarang tujuh perjalanan, apa pun jumlah barisnya: satu insert + satu
     * select untuk wali (perlu id-nya), begitu pula santri, lalu tiga insert
     * untuk turunannya.
     *
     * `insert()` melewati Eloquent, jadi `created_at`/`updated_at` diisi tangan —
     * pola yang sama sudah dipakai TagihanLainService. Aman karena tak ada model
     * di jalur ini yang punya nilai bawaan atau kait `creating`.
     */
    public function simpan(array $baris, array $param): array
    {
        $dibuat = ['santri' => 0, 'wali' => 0, 'tagihan' => 0];
        $now = now();
        $idBatch = $param['id_batch'] ?? null;

        $dibuat['wali'] = $this->simpanWali($baris, $idBatch, $now);
        [$idPerNis, $dibuat['santri']] = $this->simpanSantri($baris, $idBatch, $now);
        $dibuat['tagihan'] = $this->simpanTurunan($baris, $idPerNis, $param, $idBatch, $now);

        return $dibuat;
    }

    /**
     * Wali yang belum dikenal teleponnya — satu insert untuk semuanya.
     *
     * Telepon yang berulang DI DALAM berkas (kakak-beradik) hanya melahirkan satu
     * wali: kuncinya dipakai sebagai indeks larik, jadi kemunculan kedua menimpa
     * yang pertama alih-alih menambah baris.
     */
    private function simpanWali(array $baris, ?int $idBatch, $now): int
    {
        $baru = [];
        foreach ($baris as $b) {
            $telepon = trim($b['wali_telepon']);
            if ($telepon !== '' && ! $this->waliBerTelepon($telepon) && ! isset($baru[$telepon])) {
                $baru[$telepon] = trim($b['wali_nama']);
            }
        }
        if ($baru === []) {
            return 0;
        }

        Wali::insert(array_map(fn ($telepon, $nama) => [
            // `nama` & `telepon` di tabel wali adalah SALINAN kontak utama, bukan
            // isian tersendiri — jadi sumbernya wajib ikut diisi. Sebelumnya hanya
            // salinannya yang ditulis, sehingga wali hasil impor tak bisa disunting
            // sama sekali: WaliService::update menuntut kontak utama lengkap dan
            // menolak dengan "Kontak utama belum lengkap" walau di layar namanya
            // jelas terbaca. Berkasnya tak menyebut peran, jadi diperlakukan
            // sebagai ayah — sama dengan bawaan kolom `kontak_utama`.
            'kontak_utama' => 'ayah',
            'nama_ayah' => $nama,
            'telepon_ayah' => $telepon,
            'nama' => $nama,
            'telepon' => $telepon,
            'status' => 'aktif',
            // HANYA wali yang benar-benar LAHIR dari impor ini yang ditandai. Wali
            // yang teleponnya sudah dikenal dipakai apa adanya dan tak boleh ikut
            // terhapus saat batch dibatalkan — ia bisa saja menaungi anak dari
            // angkatan sebelumnya.
            'id_batch' => $idBatch,
            'created_at' => $now, 'updated_at' => $now,
        ], array_keys($baru), $baru));

        // Sekali select untuk memungut id-nya, lalu masuk simpanan supaya baris
        // santri di bawah bisa menunjuknya tanpa bertanya lagi.
        foreach (Wali::whereIn('telepon', array_keys($baru))->get(['id', 'nama', 'telepon']) as $w) {
            $this->waliPerTelepon[$w->telepon] = $w;
        }

        return count($baru);
    }

    /**
     * Santri — satu insert, lalu satu select untuk memetakan NIS → id.
     *
     * Id-nya dibutuhkan tiga tabel turunan, dan `insert()` massal tak
     * mengembalikannya. NIS dipakai sebagai jembatan karena ia berindeks unik dan
     * sudah dipastikan ada serta tak kembar oleh periksa().
     *
     * @return array{0: array<string,int>, 1: int}
     */
    private function simpanSantri(array $baris, ?int $idBatch, $now): array
    {
        $urut = $this->urutTerakhirNoPendaftaran();
        $rows = [];
        foreach ($baris as $b) {
            $rows[] = [
                // Awalan sendiri supaya santri lama langsung terbedakan dari
                // pendaftar PPSB dan tak mengacaukan penomoran PSB.
                'no_pendaftaran' => 'LAMA-'.str_pad((string) (++$urut), 4, '0', STR_PAD_LEFT),
                'nis' => trim($b['nis']),
                'nama' => trim($b['nama']),
                'jenis_kelamin' => strtoupper(trim($b['jenis_kelamin'])),
                'tempat_lahir' => $this->kosongJadiNull($b['tempat_lahir'] ?? ''),
                'tanggal_lahir' => $this->kosongJadiNull($b['tanggal_lahir'] ?? ''),
                'nisn' => $this->kosongJadiNull($b['nisn'] ?? ''),
                'kode_jenjang' => $this->cariJenjang(trim($b['kode_jenjang']))?->kode,
                'tingkat' => (int) trim($b['tingkat']),
                // `angkatan` SENGAJA TIDAK ditulis: tabel santri tak punya kolom
                // itu. Kode lama mengopernya ke Santri::create() dan Eloquent
                // membuangnya diam-diam lewat penyaringan $fillable, jadi isian
                // itu tak pernah tersimpan sejak awal. `insert()` tak menyaring
                // apa pun, dan justru itulah yang menyingkapkannya.
                'tahun_ajaran' => trim($b['tahun_ajaran']),
                // Santri lama masuk langsung sebagai aktif, jadi tahun yang sedang
                // DIJALANI sama dengan tahun pada berkasnya. Tanpa ini, pencarian
                // tarif SPP mereka akan buntu karena kolomnya kosong.
                'tahun_ajaran_berjalan' => trim($b['tahun_ajaran']),
                'jalur' => trim($b['jalur']),
                // Tanpa gelombang: santri lama tak boleh kena hitungan potongan gelombang.
                'gelombang' => null,
                'status' => 'aktif',
                'id_wali' => $this->waliBerTelepon(trim($b['wali_telepon']))?->id,
                // Jangkar pembatalan batch: tagihan, riwayat tingkat, dan riwayat
                // NIS semuanya menggantung di sini lewat `id_santri`, jadi hanya
                // kolom ini yang perlu penanda.
                'id_batch' => $idBatch,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }

        Santri::insert($rows);

        $nis = array_column($rows, 'nis');

        return [Santri::whereIn('nis', $nis)->pluck('id', 'nis')->all(), count($rows)];
    }

    /**
     * Riwayat tingkat, riwayat NIS, dan tunggakan — masing-masing satu insert.
     *
     * Riwayat tingkat dulu `updateOrCreate` (SELECT + INSERT per santri); di sini
     * cukup insert biasa karena santrinya baru saja lahir pada baris di atas,
     * jadi mustahil sudah punya riwayat. Dua baris untuk santri yang sama dalam
     * satu berkas pun mustahil — NIS berindeks unik dan kekembarannya sudah
     * disaring pratinjau.
     */
    private function simpanTurunan(array $baris, array $idPerNis, array $param, ?int $idBatch, $now): int
    {
        $riwayat = [];
        $nisSantri = [];
        $tagihan = [];

        foreach ($baris as $b) {
            $nis = trim($b['nis']);
            $id = $idPerNis[$nis] ?? null;
            if ($id === null) {
                continue; // tak mungkin terjadi; dilewati daripada menulis baris yatim
            }
            $kodeJenjang = $this->cariJenjang(trim($b['kode_jenjang']))?->kode;
            $tingkat = (int) trim($b['tingkat']);
            $ta = trim($b['tahun_ajaran']);

            // Baris pertama riwayat tingkatnya. Santri lama tak melewati daftar
            // ulang PPSB, jadi tanpa ini riwayatnya kosong dan kenaikan pertama
            // mereka kehilangan titik awalnya.
            if ($kodeJenjang) {
                $riwayat[] = [
                    'id_santri' => $id, 'tahun_ajaran' => $ta,
                    'kode_jenjang' => $kodeJenjang, 'tingkat' => $tingkat,
                    'catatan' => 'Impor data awal santri lama.',
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }

            // NIS bawaan dari berkas ikut dicatat sebagai riwayat pertamanya.
            // Tanpa ini ia berdiri di luar catatan, dan layar Generate NIS akan
            // mengira santri ini belum pernah bernomor lalu menawarkan yang baru.
            $nisSantri[] = [
                'id_santri' => $id, 'nis' => $nis,
                'kode_jenjang' => $kodeJenjang, 'tingkat' => $tingkat,
                'tahun_ajaran' => $ta, 'berlaku' => true,
                'diterbitkan_pada' => $now->toDateString(),
                'created_at' => $now, 'updated_at' => $now,
            ];

            foreach (self::TUNGGAKAN as $k => $t) {
                $nilai = $this->angka($b["tunggakan_{$k}"] ?? '');
                if ($nilai === null || ! Money::gtZero($nilai)) {
                    continue;
                }
                $kodeJenis = trim($param["jenis_tunggakan_{$k}"] ?? '');
                $tagihan[] = [
                    'id_santri' => $id,
                    // Penanda batch — ini yang membedakan tunggakan hasil impor
                    // dari tagihan yang diterbitkan petugas kemudian. Tanpa itu
                    // pembatalan batch tak punya cara pasti membedakan keduanya.
                    'id_batch' => $idBatch,
                    'kode_jenis' => $kodeJenis,
                    // Perilaku disalin dari jenis biayanya supaya tunggakan uang
                    // pangkal warisan tetap dikenali modul yang membacanya.
                    'perilaku' => TipeBiaya::perilakuDari($this->jenisBiaya($kodeJenis)?->tipe),
                    'kode_jenjang' => $kodeJenjang,
                    'tahun_ajaran' => $ta,
                    'nominal' => $nilai,
                    'sisa' => $nilai,
                    'status' => 'belum_bayar',
                    // Nilainya sudah diakui sebagai pendapatan di catatan lama →
                    // pembayaran nanti mengkredit PIUTANG, bukan Pendapatan.
                    'sudah_akrual' => true,
                    'keterangan' => $this->kosongJadiNull($b["ket_tunggakan_{$k}"] ?? '') ?? $t['bawaan_ket'],
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }

        if ($riwayat !== []) {
            RiwayatTingkat::insert($riwayat);
        }
        if ($nisSantri !== []) {
            \App\Models\NisSantri::insert($nisSantri);
        }
        if ($tagihan !== []) {
            TagihanSantri::insert($tagihan);
        }

        return count($tagihan);
    }


    private function urutTerakhirNoPendaftaran(): int
    {
        $last = Santri::where('no_pendaftaran', 'like', 'LAMA-%')
            ->orderByDesc('no_pendaftaran')->value('no_pendaftaran');

        return $last ? (int) substr($last, 5) : 0;
    }
}
