<?php

namespace App\Console\Commands;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\JalurPendaftaran;
use App\Models\JenisBiaya;
use App\Models\Jenjang;
use App\Models\Santri;
use App\Models\SumberInformasi;
use App\Models\TagihanSantri;
use App\Models\TipeBiaya;
use App\Models\User;
use App\Models\Wali;
use App\Services\Impor\ImporSaldoAwal;
use App\Services\Modules\PembayaranSantriService;
use App\Services\Modules\PotonganGelombangService;
use App\Services\Modules\SantriService;
use App\Services\Modules\TahunAjaranService;
use App\Services\Modules\WaliService;
use App\Support\Money;
use Illuminate\Console\Command;

/**
 * DATA UJI (dummy) untuk PPSB & Kependidikan.
 *
 * Semuanya ditulis LEWAT SERVICE yang dipakai layar — WaliService, SantriService,
 * PembayaranSantriService, dan pemeta impor Santri Lama — bukan insert mentah.
 * Itu memang tujuannya: perintah ini sekalian menjalankan alur aplikasinya, jadi
 * penjaga yang seharusnya menolak akan menolak di sini juga, bukan baru ketahuan
 * saat seseorang mengetik satu per satu di layar.
 *
 * Yang dibuat:
 *  • PPSB — 20 wali (keluarga) + 60 calon santri, 20 per jenjang, tahapannya
 *    tersebar dari "calon" sampai "mengundurkan diri". Yang melewati tahap
 *    registrasi benar-benar dibayarkan & diverifikasi keuangan, jadi jurnalnya
 *    ikut terbit — status "terbayar" tak bisa didapat tanpa itu.
 *  • KEPENDIDIKAN — 120 santri aktif lewat menu Impor Data Awal → Santri Lama
 *    (60 SDTQ @10 per tingkat, 30 SMP, 30 SMA), sebagian membawa tunggakan.
 *    Santri aktif SENGAJA tidak dibuat lewat PPSB: alur itu akan mengarang
 *    penerimaan uang & pendapatan yang tak pernah ada (lihat PemetaSantriLama).
 *
 * Aman diulang: wali dicocokkan lewat telepon, calon lewat NISN, santri lama
 * lewat NIS — yang sudah ada dilewati, bukan dibuat dua kali.
 */
class IsiDataDummy extends Command
{
    protected $signature = 'dummy:isi
        {--ta= : Tahun ajaran sasaran (bawaan: T.A default pendaftaran)}
        {--bagian=semua : semua|ppsb|kependidikan}';

    protected $description = 'Isi data uji: 20 wali + 60 calon santri PPSB (tahapan beragam) + 120 santri aktif Kependidikan.';

    /** Jenjang yang dipakai, beserta jatah calon & santri aktifnya. */
    private const JENJANG = ['J001', 'J002', 'J003'];

    /** Umur masuk tingkat 1 per jenjang — dasar menghitung tanggal lahir. */
    private const UMUR_TINGKAT_SATU = ['J001' => 7, 'J002' => 13, 'J003' => 16];

    /**
     * Rencana tahapan 20 calon per jenjang. Indeksnya juga menentukan jalur,
     * gelombang, dan tingkat (lihat rencanaCalon()).
     *
     * Empat "calon" sengaja tidak seragam: satu sudah menyetor tapi BELUM
     * diverifikasi keuangan (urut 1), tiga belum menyetor sama sekali — keduanya
     * tampil berbeda di daftar & dashboard. Satu "terbayar" (urut 4) sampai di
     * situ TANPA membayar apa pun karena tarif registrasi jalurnya bertanda
     * BEBAS — jalan yang berbeda ke status yang sama, dan itu perlu terwakili.
     */
    private const TAHAP = [
        'calon', 'calon', 'calon', 'calon',
        'terbayar', 'terbayar', 'terbayar',
        'terverifikasi', 'terverifikasi',
        'diseleksi', 'diseleksi',
        'diterima', 'diterima', 'diterima',
        'lolos_kesehatan', 'lolos_kesehatan',
        'tidak_lulus', 'tidak_lulus',
        'gagal_medcheck',
        'mengundurkan_diri',
    ];

    /**
     * 20 keluarga PPSB. `anak` menentukan berapa calon yang didaftarkan keluarga
     * itu — totalnya 60. Kakak-beradik memang mendaftar bersamaan di pesantren,
     * dan satu wali dengan beberapa anak adalah keadaan yang paling sering salah
     * ditangani (dompet wali dibagi satu keluarga), jadi justru harus terwakili.
     *
     * Nama ayah ditulis TANPA nama keluarga — keluarganya disematkan saat dipakai,
     * supaya nama anak & ayah pasti sekeluarga tanpa ada yang tertulis dua kali.
     *
     * [keluarga, ayah, ibu, pekerjaan ayah, pekerjaan ibu, pendapatan, kota, kontak utama, jumlah anak]
     */
    private const WALI_PPSB = [
        ['Nasution', 'Abdul Rahman', 'Siti Aminah', 'Pegawai Negeri Sipil', 'Ibu Rumah Tangga', 'juta_5_10', 'Medan', 'ayah', 2],
        ['Hidayat', 'Syarif', 'Nurul Fadhilah', 'Guru', 'Guru', 'juta_5_10', 'Bogor', 'ayah', 3],
        ['Pratama', 'Bambang', 'Retno Wulandari', 'Wiraswasta', 'Ibu Rumah Tangga', 'juta_15_25', 'Bekasi', 'ayah', 4],
        ['Maulana', 'Ahmad', 'Dewi Sartika', 'Karyawan Swasta', 'Bidan', 'juta_10_15', 'Depok', 'ibu', 3],
        ['Firdaus', 'Zulkifli', 'Halimah Sadiyah', 'Pedagang', 'Ibu Rumah Tangga', 'di_bawah_5', 'Tangerang', 'ayah', 2],
        ['Kurniawan', 'Eko', 'Sri Lestari', 'TNI', 'Ibu Rumah Tangga', 'juta_10_15', 'Jakarta Timur', 'ayah', 3],
        ['Wibowo', 'Hendra', 'Ratna Juwita', 'Dokter', 'Apoteker', 'di_atas_25', 'Jakarta Selatan', 'ayah', 4],
        ['Saputra', 'Iwan', 'Yuliana', 'Sopir', 'Penjahit', 'di_bawah_5', 'Cibinong', 'ibu', 3],
        ['Ramadhan', 'Fauzi', 'Kartika Sari', 'Dosen', 'Ibu Rumah Tangga', 'juta_15_25', 'Bandung', 'ayah', 2],
        ['Al Farisi', 'Salman', 'Aisyah Putri', 'Pengusaha', 'Ibu Rumah Tangga', 'di_atas_25', 'Surabaya', 'ayah', 3],
        ['Siregar', 'Rudi', 'Mariana', 'Petani', 'Ibu Rumah Tangga', 'di_bawah_5', 'Padang Sidempuan', 'ayah', 4],
        ['Gunawan', 'Asep', 'Euis Komariah', 'Karyawan Swasta', 'Pedagang', 'juta_5_10', 'Sukabumi', 'ayah', 3],
        ['Santoso', 'Joko', 'Endang Susilowati', 'Polri', 'Ibu Rumah Tangga', 'juta_10_15', 'Semarang', 'ayah', 2],
        ['Wijaya', 'Chandra', 'Melati Putri', 'Notaris', 'Ibu Rumah Tangga', 'di_atas_25', 'Jakarta Barat', 'wali', 3],
        ['Halim', 'Fadhil', 'Zahara Ummu', 'Ustadz', 'Guru Ngaji', 'di_bawah_5', 'Cirebon', 'ayah', 4],
        ['Harahap', 'Sutan', 'Nur Aisyah', 'Kontraktor', 'Ibu Rumah Tangga', 'juta_15_25', 'Pekanbaru', 'ayah', 3],
        ['Lubis', 'Amran', 'Siti Khadijah', 'Perawat', 'Ibu Rumah Tangga', 'juta_5_10', 'Binjai', 'ibu', 2],
        ['Nugraha', 'Dedi', 'Wulan Sari', 'Arsitek', 'Desainer', 'juta_15_25', 'Yogyakarta', 'ayah', 3],
        ['Setiawan', 'Anton', 'Rina Marlina', 'Akuntan', 'Ibu Rumah Tangga', 'juta_10_15', 'Malang', 'ayah', 4],
        ['Rangkuti', 'Ilham', 'Fitri Handayani', 'Nelayan', 'Ibu Rumah Tangga', 'di_bawah_5', 'Sibolga', 'ayah', 3],
    ];

    private const NAMA_PUTRA = [
        'Ahmad Fauzan', 'Muhammad Rizky', 'Abdullah Hafizh', 'Umar Naufal', 'Ali Ridwan',
        'Hasan Syafiq', 'Husein Luthfi', 'Yusuf Alif', 'Ibrahim Fathan', 'Ismail Azzam',
        'Zaid Ghazi', 'Bilal Arkan', 'Salman Faris', 'Hamzah Zaki', 'Anas Rasyid',
        'Khalid Mumtaz', 'Utsman Nabil', 'Thalhah Ihsan', 'Zubair Hakim', 'Muadz Fikri',
    ];

    private const NAMA_PUTRI = [
        'Aisyah Zahra', 'Khadijah Nabila', 'Fatimah Azka', 'Zainab Salsabila', 'Maryam Kamila',
        'Hafshah Naila', 'Ruqayyah Husna', 'Safiyyah Afifah', 'Halimah Syifa', 'Aliyah Hanifah',
        'Qonita Alya', 'Inara Shofia', 'Adiba Nadhira', 'Rahma Talita', 'Kayla Humaira',
        'Zulfa Amira', 'Hafizah Ulya', 'Nasywa Adzkia', 'Raisa Aqila', 'Nadia Rifdah',
    ];

    /**
     * Nama tengah. Ada bukan demi keindahan: tanpa pembeda ketiga, 20 nama depan
     * × 40 nama keluarga yang dipetik bergilir menghasilkan puluhan santri BERNAMA
     * PERSIS SAMA di antara 180 baris — dan saat mengklik satu baris untuk diuji,
     * tak ada cara tahu yang mana yang sedang dibuka. Jumlahnya 7 (prima, tak
     * membagi 20 maupun 40) sehingga ketiga daftar tak pernah berulang serentak.
     */
    private const NAMA_TENGAH = ['Abdullah', 'Hafizh', 'Zayyan', 'Faiz', 'Rasyad', 'Athaya', 'Naufal'];

    private const KOTA = [
        'Bogor', 'Bekasi', 'Depok', 'Tangerang', 'Jakarta', 'Bandung', 'Sukabumi', 'Cianjur',
        'Serang', 'Cilegon', 'Karawang', 'Purwakarta', 'Subang', 'Garut', 'Tasikmalaya', 'Cirebon',
    ];

    /** Sekolah asal per jenjang — SDTQ dari TK, SMP dari SD, SMA dari SMP. */
    private const SEKOLAH_ASAL = [
        'J001' => ['TK Islam Al Hidayah', 'TKIT Nurul Ilmi', 'RA Al Muhajirin'],
        'J002' => ['SDIT Nurul Fikri', 'SD Islam Al Azhar', 'SDIT Insan Kamil'],
        'J003' => ['SMPIT Al Maarif', 'SMP Islam Terpadu Assalam', 'SMPIT Ummul Quro'],
    ];

    /**
     * Kode jalur pendaftaran yang dipakai rencana ini. Kodenya memang dipaku:
     * perannya berbeda-beda dan itu bagian dari yang diuji (005 = jalur bebas
     * biaya registrasi, 006 = One Stop Schooling khusus SMP). Keberadaannya
     * diperiksa di siapkan() supaya pesannya jelas bila masternya berbeda.
     */
    private const JALUR = ['001', '002', '005', '006'];

    /** Nama keluarga untuk santri lama — tak perlu sama dengan keluarga PPSB. */
    private const KELUARGA_LAMA = [
        'Abdurrahman', 'Alatas', 'Assegaf', 'Baswedan', 'Fadhlurrahman', 'Habibie', 'Hakim',
        'Iskandar', 'Jamaluddin', 'Kamaruddin', 'Mahmud', 'Mansyur', 'Muhaimin', 'Mukhtar',
        'Nawawi', 'Qomaruddin', 'Rifai', 'Sanusi', 'Shihab', 'Syamsuddin', 'Taufiqurrahman',
        'Umarsyah', 'Wahhab', 'Yahya', 'Zainuddin', 'Bahri', 'Cholil', 'Dahlan', 'Elyas',
        'Ghufron', 'Hanafi', 'Ilyas', 'Junaedi', 'Kholiq', 'Latif', 'Mubarok', 'Nashiruddin',
        'Oemar', 'Purnomo', 'Qosim',
    ];

    private SantriService $santri;

    private PembayaranSantriService $pembayaran;

    private string $ta;

    private int $idPpsb;

    private int $idKeuangan;

    private string $rekening;

    /** Sumber informasi aktif dari master: [kode => butuh_keterangan]. */
    private array $sumberInfo = [];

    /** Catatan yang dilaporkan di akhir — masalah data master ikut ke sini. */
    private array $catatan = [];

    private array $hitung = [
        'wali' => 0, 'wali_disegarkan' => 0, 'calon' => 0, 'calon_lewati' => 0,
        'bayar' => 0, 'bayar_menunggu' => 0, 'registrasi_bebas' => 0, 'tagihan_uang_pangkal' => 0,
        'aktif' => 0, 'wali_aktif' => 0, 'tunggakan' => 0,
    ];

    public function handle(ImporSaldoAwal $impor): int
    {
        $this->santri = new SantriService;
        $this->pembayaran = new PembayaranSantriService;

        try {
            $this->siapkan();
        } catch (AppException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $bagian = (string) $this->option('bagian');
        $this->info("T.A sasaran: {$this->ta} · rekening penerima: {$this->rekening}");

        if (in_array($bagian, ['semua', 'ppsb'], true)) {
            $this->newLine();
            $this->info('== PPSB: wali & calon santri ==');
            $this->isiPpsb();
        }

        if (in_array($bagian, ['semua', 'kependidikan'], true)) {
            $this->newLine();
            $this->info('== Kependidikan: santri aktif (Impor Santri Lama) ==');
            $this->isiSantriAktif($impor);
        }

        $this->laporkan();

        return self::SUCCESS;
    }

    /** Prasyarat: T.A, jenjang, rekening penerima, dan dua pengguna pelaksana. */
    private function siapkan(): void
    {
        $ta = (string) ($this->option('ta') ?: (new TahunAjaranService)->defaultPendaftaran()?->kode);
        if ($ta === '') {
            throw new AppException(422, 'Tidak ada tahun ajaran default pendaftaran. Sebutkan lewat --ta=2027/2028.');
        }
        (new TahunAjaranService)->pastikanAktif($ta);
        $this->ta = $ta;

        foreach (self::JENJANG as $kode) {
            $j = Jenjang::find($kode);
            if (! $j || ! $j->jumlah_tingkat) {
                throw new AppException(422, "Jenjang {$kode} belum ada / jumlah tingkatnya belum diisi.");
            }
        }

        $hilang = array_diff(self::JALUR, JalurPendaftaran::whereIn('kode', self::JALUR)
            ->where('status', 'aktif')->pluck('kode')->all());
        if ($hilang !== []) {
            throw new AppException(422, 'Jalur pendaftaran '.implode(', ', $hilang).' belum ada / nonaktif di master. '
                .'Rencana data uji ini memakai keempatnya (001 reguler, 002 pindahan, 005 bebas registrasi, 006 OSS).');
        }

        // Sumber informasi diambil dari MASTER, bukan dipaku: daftarnya dikelola
        // tiap pesantren, dan kolomnya berkunci asing ke sana.
        $this->sumberInfo = SumberInformasi::where('status', 'aktif')
            ->orderBy('urutan')->orderBy('kode')->pluck('butuh_keterangan', 'kode')->all();

        $rekening = BankAccount::where('status', 'aktif')->orderBy('kode_coa')->value('kode_coa');
        if (! $rekening) {
            throw new AppException(422, 'Belum ada kas/rekening aktif untuk menerima pembayaran registrasi.');
        }
        $this->rekening = $rekening;

        // Pencatat = petugas PPSB, verifikator = tim keuangan. Dipisah supaya
        // alur dua tangan benar-benar dilalui, bukan disingkat satu pengguna.
        $keuangan = User::where('status', 'aktif')->where('tim_keuangan', true)
            ->orderBy('is_admin')->value('id_pengguna');
        if (! $keuangan) {
            throw new AppException(422, 'Belum ada pengguna tim keuangan yang bisa memverifikasi pembayaran.');
        }
        $this->idKeuangan = (int) $keuangan;
        $this->idPpsb = (int) (User::where('status', 'aktif')->where('is_admin', true)->value('id_pengguna') ?? $keuangan);
    }

    // ---------------- PPSB ----------------

    private function isiPpsb(): void
    {
        $slot = 0; // urutan calon global; jenjang & tahapan diturunkan dari sini

        foreach (self::WALI_PPSB as $i => $w) {
            [$keluarga, $ayah, $ibu, $kerjaAyah, $kerjaIbu, $pendapatan, $kota, $kontak, $anak] = $w;
            $wali = $this->waliPpsb($i, $keluarga, $ayah, $ibu, $kerjaAyah, $kerjaIbu, $pendapatan, $kota, $kontak);

            for ($n = 0; $n < $anak; $n++, $slot++) {
                $this->calon($wali, $keluarga, $slot);
            }
        }
    }

    private function waliPpsb(int $i, string $keluarga, string $ayah, string $ibu, string $kerjaAyah, string $kerjaIbu, string $pendapatan, string $kota, string $kontak): Wali
    {
        $telepon = $this->teleponPpsb($i);

        $data = [
            'kontak_utama' => $kontak,
            'nama_ayah' => "{$ayah} {$keluarga}", 'telepon_ayah' => $telepon,
            'email_ayah' => $this->email($ayah, $keluarga), 'pekerjaan_ayah' => $kerjaAyah,
            'pendapatan_ayah' => $pendapatan,
            'nama_ibu' => "{$ibu}", 'telepon_ibu' => $this->teleponPpsb($i + 100),
            'email_ibu' => $this->email($ibu, $keluarga), 'pekerjaan_ibu' => $kerjaIbu,
            'pendapatan_ibu' => 'di_bawah_5',
            'nik' => sprintf('32%014d', 71010100000 + $i),
            'alamat' => 'Jl. Pesantren No. '.($i + 1).", {$kota}",
            'auto_debet' => $i % 4 === 0,
            'status' => 'aktif',
        ];

        // Nomor kontak UTAMA selalu teleponPpsb($i) siapa pun kontak utamanya —
        // itulah nomor yang disalin ke `wali.telepon` dan menjadi kunci pengait
        // saat santri lama ditempelkan ke keluarga yang sama.
        if ($kontak === 'wali') {
            // Kontak utama "wali" (bukan ayah/ibu) butuh pasangan nama+telepon
            // sendiri; tanpa itu WaliService menolak — diikuti, bukan diakali.
            $data['nama_wali'] = "Kakek {$keluarga}";
            $data['telepon_wali'] = $telepon;
            $data['telepon_ayah'] = $this->teleponPpsb($i + 100);
            $data['email_wali'] = $this->email('wali', $keluarga);
            $data['pekerjaan_wali'] = 'Pensiunan';
            $data['pendapatan_wali'] = 'juta_5_10';
        }
        if ($kontak === 'ibu') {
            $data['telepon_ibu'] = $telepon;
            $data['telepon_ayah'] = $this->teleponPpsb($i + 100);
        }

        // Sudah ada → DISEGARKAN lewat WaliService::update, bukan dilewati begitu
        // saja: menjalankan ulang perintah ini setelah datanya diperbaiki harus
        // ikut membetulkan wali yang sudah masuk, dan sekalian menguji jalur
        // sunting wali (penyalinan kontak utama + penjaga telepon unik).
        // Dicari lewat SEMUA nomor yang mungkin dipegang indeks ini (ayah/ibu/wali),
        // bukan hanya nomor utamanya: jalannya perintah versi sebelumnya bisa
        // menyimpan nomor lain sebagai kontak utama, dan mencari hanya satu nomor
        // akan membuat wali yang sama dibuat dua kali.
        $ada = Wali::whereIn('telepon', [$telepon, $this->teleponPpsb($i + 100), $this->teleponPpsb($i + 200)])->first();
        if ($ada) {
            $wali = (new WaliService)->update($ada->id, $data);
            $this->hitung['wali_disegarkan']++;

            return $wali;
        }

        $wali = (new WaliService)->create($data);
        $this->hitung['wali']++;

        return $wali;
    }

    /** Satu calon santri: didaftarkan, lalu dimajukan sampai tahapan rencananya. */
    private function calon(Wali $wali, string $keluarga, int $slot): void
    {
        $r = $this->rencanaCalon($slot, $keluarga);

        if (Santri::where('nisn', $r['nisn'])->exists()) {
            $this->hitung['calon_lewati']++;

            return;
        }

        try {
            $santri = $this->santri->create([
                'id_wali' => $wali->id,
                'nama' => $r['nama'], 'jenis_kelamin' => $r['jenis_kelamin'],
                'tempat_lahir' => $r['tempat_lahir'], 'tanggal_lahir' => $r['tanggal_lahir'],
                'nisn' => $r['nisn'], 'asal_sekolah' => $r['asal_sekolah'],
                'kepala_sekolah_asal' => $r['kepala_sekolah'], 'cp_kepala_sekolah_asal' => $r['cp_kepala_sekolah'],
                'kode_jenjang' => $r['kode_jenjang'], 'tingkat' => $r['tingkat'],
                'tahun_ajaran' => $this->ta, 'jalur' => $r['jalur'], 'gelombang' => $r['gelombang'],
                'sumber_informasi' => $r['sumber_informasi'],
                'sumber_informasi_lain' => $r['sumber_informasi_lain'],
            ]);
        } catch (AppException $e) {
            $this->catatan[] = "Calon {$r['nama']} ({$r['kode_jenjang']}, jalur {$r['jalur']}) gagal didaftarkan: ".$e->getMessage();

            return;
        }

        $this->hitung['calon']++;
        $this->majukan($santri, $r);
    }

    /**
     * Rencana satu calon. Jenjang dibagi bergilir (slot % 3) sehingga tepat 20
     * per jenjang, dan satu keluarga yang mendaftarkan 3 anak otomatis menyebar
     * ke SDTQ, SMP, dan SMA — persis kejadian yang paling sering di lapangan.
     */
    private function rencanaCalon(int $slot, string $keluarga): array
    {
        $jenjang = self::JENJANG[$slot % 3];
        $urut = intdiv($slot, 3);              // 0..19 dalam jenjangnya
        $tahap = self::TAHAP[$urut];

        // Jalur: indeks 4 = jalur bebas biaya registrasi (Anak Karyawan) — ditaruh
        // di slot "terbayar" karena tahapnya memang dilewati tanpa membayar;
        // beberapa indeks = pindahan (masuk di tengah jenjang, tanpa gelombang);
        // satu untuk SMP = One Stop Schooling. Sisanya reguler.
        $jalur = match (true) {
            $urut === 4 => '005',
            in_array($urut, [2, 8, 13], true) => '002',
            in_array($urut, [5, 11], true) && $jenjang === 'J002' => '006',
            default => '001',
        };
        $pindahan = $jalur === '002';

        $tingkat = $pindahan ? 2 : 1;
        $gelombang = match (true) {
            $pindahan => null,           // pindahan tak pernah dapat potongan gelombang
            $urut === 15 => 2,
            default => 1 + ($urut % 2),
        };

        $lakiLaki = $slot % 2 === 0;
        $nama = $this->namaSantri($lakiLaki, $urut, $slot, $keluarga);
        $umur = self::UMUR_TINGKAT_SATU[$jenjang] + ($tingkat - 1);
        $tahunLahir = (int) substr($this->ta, 0, 4) - $umur;

        // Sumber informasi bergilir menurut master; yang butuh keterangan diisi
        // keterangannya, supaya isian bersyarat itu ikut terwakili di data.
        $kodeSumber = array_keys($this->sumberInfo);
        $sumber = $kodeSumber === [] ? null : $kodeSumber[$urut % count($kodeSumber)];

        return [
            'nama' => $nama,
            'jenis_kelamin' => $lakiLaki ? 'L' : 'P',
            'tempat_lahir' => self::KOTA[$slot % count(self::KOTA)],
            'tanggal_lahir' => sprintf('%04d-%02d-%02d', $tahunLahir, ($slot % 12) + 1, ($slot % 27) + 1),
            'nisn' => sprintf('00%08d', 30000001 + $slot),
            'asal_sekolah' => self::SEKOLAH_ASAL[$jenjang][$urut % 3],
            'kepala_sekolah' => 'Drs. '.self::KELUARGA_LAMA[$urut % count(self::KELUARGA_LAMA)],
            'cp_kepala_sekolah' => sprintf('0813%07d', 5000001 + $slot),
            'kode_jenjang' => $jenjang,
            'tingkat' => $tingkat,
            'jalur' => $jalur,
            'gelombang' => $gelombang,
            'sumber_informasi' => $sumber,
            'sumber_informasi_lain' => ($sumber !== null && $this->sumberInfo[$sumber]) ? 'Rekomendasi alumni angkatan pertama' : null,
            'tahap' => $tahap,
            'urut' => $urut,
        ];
    }

    /**
     * Majukan calon sampai tahapan rencananya, lewat mesin Tahap. Urutannya tak
     * bisa dilompati: itu memang inti pengujiannya.
     */
    private function majukan(Santri $santri, array $r): void
    {
        $tahap = $r['tahap'];

        if ($tahap === 'calon') {
            // Indeks 1: setoran registrasi sudah dicatat PPSB tapi belum
            // diverifikasi keuangan — santrinya tetap "calon", dan daftarnya
            // harus menampilkannya sebagai "menunggu", bukan "belum bayar".
            if ($r['urut'] === 1) {
                $this->bayarRegistrasi($santri, verifikasi: false);
            }

            return;
        }

        if (! $this->bayarRegistrasi($santri, verifikasi: true)) {
            return; // tak ada tagihan registrasi → tahapnya memang tak bisa maju
        }
        if ($tahap === 'terbayar') {
            return;
        }

        $santri = $this->santri->verifikasiBerkas($santri->id);
        if ($tahap === 'terverifikasi') {
            return;
        }
        if ($tahap === 'mengundurkan_diri') {
            $this->santri->mengundurkanDiri($santri->id, 'Wali memilih pesantren lain yang lebih dekat.', $this->idPpsb);

            return;
        }

        $this->santri->seleksi($santri->id, [
            'nilai_baca' => number_format(65 + ($r['urut'] % 30) + 0.5, 2, '.', ''),
            'nilai_akademik' => number_format(60 + ($r['urut'] % 35) + 0.25, 2, '.', ''),
            'wawancara_wali' => 'Wali sanggup memenuhi kewajiban biaya dan mendukung program tahfizh.',
            'wawancara_santri' => 'Calon santri bersedia tinggal di asrama dan mengikuti aturan pesantren.',
            'catatan' => 'Hasil tes baca Al-Quran dan akademik terekam.',
        ]);
        if ($tahap === 'diseleksi') {
            return;
        }

        if ($tahap === 'tidak_lulus') {
            $this->santri->pengumuman($santri->id, ['lulus' => false, 'catatan' => 'Nilai seleksi di bawah ambang penerimaan.']);

            return;
        }

        $this->santri->pengumuman($santri->id, ['lulus' => true, 'catatan' => 'Diterima pada seleksi gelombang berjalan.']);
        if ($tahap === 'diterima') {
            return;
        }

        if ($tahap === 'gagal_medcheck') {
            $this->santri->medcheck($santri->id, [
                'lolos' => false, 'dokumen_lengkap' => true,
                'catatan' => 'Hasil med check memerlukan perawatan lanjutan di luar pesantren.',
            ]);

            return;
        }

        $this->santri->medcheck($santri->id, [
            'lolos' => true, 'dokumen_lengkap' => true, 'catatan' => 'Sehat, tanpa catatan khusus.',
        ]);

        // Tahapan berikutnya setelah lolos kesehatan adalah penagihan uang pangkal
        // (+ perlengkapan). Daftar ulang sengaja TIDAK dijalankan di sini: itu
        // menerbitkan NIS & akrual, dan enak diuji sendiri dari layar.
        $this->tagihUangPangkal($santri->refresh());
    }

    /**
     * Bayar tagihan registrasi (satu setoran penuh) lalu — bila diminta —
     * verifikasi keuangan, yang menerbitkan jurnal dan memajukan calon.
     *
     * @return bool false bila tak ada tagihan registrasi yang bisa dibayar
     */
    private function bayarRegistrasi(Santri $santri, bool $verifikasi): bool
    {
        $tagihan = TagihanSantri::where('id_santri', $santri->id)->where('perilaku', 'registrasi')
            ->where('status', '!=', 'batal')->orderByDesc('id')->first();

        if (! $tagihan) {
            // Tarif registrasi BEBAS → tak ada yang perlu dibayar, dan
            // SantriService::create sudah memajukan tahapnya sendiri saat calon
            // didaftarkan. Yang perlu diwaspadai justru kalau ia TIDAK maju:
            // itu berarti penjaga di service tak berjalan.
            if ($santri->refresh()->status === 'terbayar') {
                $this->hitung['registrasi_bebas']++;

                return true;
            }

            $this->catatan[] = "{$santri->nama} ({$santri->no_pendaftaran}, jenjang {$santri->kode_jenjang}, jalur {$santri->jalur}): "
                .'tak ada tagihan registrasi, tetapi statusnya masih "'.$santri->status.'" — '
                .'tahapnya tertahan dan tak ada yang bisa dibayarkan. Periksa sel tarif registrasinya.';

            return false;
        }

        try {
            $p = $this->pembayaran->catat([
                'id_santri' => $santri->id, 'id_tagihan' => $tagihan->id,
                'tanggal' => now()->toDateString(), 'nominal' => $tagihan->sisa,
                'kode_rekening' => $this->rekening, 'metode' => 'transfer',
                'catatan' => 'Setoran biaya registrasi (data uji).',
            ], $this->idPpsb, 'ppsb');
        } catch (AppException $e) {
            $this->catatan[] = "Pembayaran registrasi {$santri->nama} gagal dicatat: ".$e->getMessage();

            return false;
        }

        if (! $verifikasi) {
            $this->hitung['bayar_menunggu']++;

            return false;
        }

        try {
            $this->pembayaran->verifikasi($p->id, $this->idKeuangan);
        } catch (AppException $e) {
            $this->catatan[] = "Verifikasi pembayaran {$santri->nama} gagal: ".$e->getMessage();

            return false;
        }
        $this->hitung['bayar']++;

        return true;
    }

    /**
     * Terbitkan uang pangkal + perlengkapan sebesar tarifnya. Angkanya diambil
     * dari grid Tarif, sama seperti angka bawaan di layar.
     */
    private function tagihUangPangkal(Santri $santri): void
    {
        try {
            $up = $this->santri->komponen('uang_pangkal', $santri->tahun_ajaran, $santri->kode_jenjang, $santri->jalur)['tarif'];
            $plk = $this->santri->komponen('perlengkapan', $santri->tahun_ajaran, $santri->kode_jenjang, $santri->jalur)['tarif'];
        } catch (AppException $e) {
            $this->catatan[] = "Tarif uang pangkal/perlengkapan {$santri->nama} tak lengkap: ".$e->getMessage();

            return;
        }

        if ($up['status'] !== 'ada') {
            // Bebas = memang tak ditagih; kosong = selnya belum diisi petugas.
            if ($up['status'] !== 'bebas') {
                $this->catatan[] = "Uang pangkal {$santri->nama} tak bisa ditagih: ".$up['label'];
            }

            return;
        }

        // Penjaga di service akan menolak potongan ≥ nominal. Diperiksa lebih dulu
        // di sini supaya laporannya menyebut sel master mana yang keliru, bukan
        // sekadar satu baris gagal di tengah ratusan.
        $potongan = (new PotonganGelombangService)->potonganAktif($santri->gelombang, $santri->kode_jenjang, $santri->tahun_ajaran);
        if ($potongan && Money::gte($potongan->potongan, $up['nominal'])) {
            $this->catatan[] = "MASTER KELIRU — potongan Gelombang {$santri->gelombang} jenjang {$santri->kode_jenjang} "
                ."T.A {$santri->tahun_ajaran} sebesar {$potongan->potongan} ≥ uang pangkalnya ({$up['nominal']}). "
                ."Uang pangkal {$santri->nama} tidak diterbitkan; perbaiki dulu di PPSB → Potongan Gelombang.";

            return;
        }

        try {
            $this->santri->tagihkanUangPangkal($santri->id, [
                'nominal' => $up['nominal'],
                'nominal_perlengkapan' => $plk['status'] === 'ada' ? $plk['nominal'] : '0',
                'jatuh_tempo' => now()->addDays(30)->toDateString(),
            ]);
            $this->hitung['tagihan_uang_pangkal']++;
        } catch (AppException $e) {
            $this->catatan[] = "Penagihan uang pangkal {$santri->nama} gagal: ".$e->getMessage();
        }
    }

    // ---------------- Kependidikan: santri aktif ----------------

    /**
     * Santri aktif dimasukkan lewat pemeta Impor Data Awal → Santri Lama, satu
     * berkas per jenjang. Dipisah per jenjang karena parameter jenis biaya
     * tunggakan berlaku untuk seluruh berkas, sedangkan jenis biaya sendiri
     * melekat pada jenjang.
     */
    private function isiSantriAktif(ImporSaldoAwal $impor): void
    {
        $folder = storage_path('app/private/impor-dummy');
        if (! is_dir($folder)) {
            mkdir($folder, 0777, true);
        }

        // [jenjang => jumlah santri per tingkat]
        $rencana = ['J001' => 10, 'J002' => 10, 'J003' => 10];
        $urutNis = [];   // penomoran NIS per tahun masuk
        $slot = 0;       // urutan santri aktif global (penentu wali & tunggakan)

        foreach ($rencana as $jenjang => $perTingkat) {
            $tingkatMaks = (int) Jenjang::find($jenjang)->jumlah_tingkat;
            $baris = [];

            for ($tingkat = 1; $tingkat <= $tingkatMaks; $tingkat++) {
                for ($n = 0; $n < $perTingkat; $n++, $slot++) {
                    $baris[] = $this->barisSantriLama($jenjang, $tingkat, $slot, $urutNis);
                }
            }

            $path = $folder.DIRECTORY_SEPARATOR."santri-lama-{$jenjang}.csv";
            $this->tulisCsv($path, $baris);

            $param = [
                'jenis_tunggakan_spp' => $this->jenisTunggakan($jenjang, 'spp'),
                'jenis_tunggakan_uang_pangkal' => $this->jenisTunggakan($jenjang, 'uang_pangkal'),
                'jenis_tunggakan_daftar_ulang' => $this->jenisTunggakan($jenjang, 'daftar_ulang'),
                'jenis_tunggakan_lain' => $this->jenisTunggakan($jenjang, 'lain'),
            ];

            // Pratinjau dulu — alur di layar memang mewajibkannya, dan di sini
            // gunanya memastikan tak ada baris bermasalah sebelum menulis.
            $pratinjau = $impor->pratinjau('santri-lama', $path, $param);
            if ($pratinjau['masalah'] > 0) {
                foreach ($pratinjau['baris_masalah'] as $m) {
                    $this->catatan[] = "Impor {$jenjang} baris {$m['nomor']}: {$m['alasan']}";
                }
            }

            // Tak ada yang siap = seluruh barisnya sudah pernah masuk. Itu keadaan
            // NORMAL saat perintah dijalankan ulang; `jalankan()` sendiri menolak
            // berkas semacam itu (422), jadi jangan sampai dipanggil.
            if ($pratinjau['siap'] === 0) {
                $this->line("  {$jenjang}: seluruh {$pratinjau['lewati']} baris sudah pernah diimpor — dilewati.");

                continue;
            }

            $hasil = $impor->jalankan('santri-lama', $path, $param);
            $this->hitung['aktif'] += $hasil['tersimpan']['santri'];
            $this->hitung['wali_aktif'] += $hasil['tersimpan']['wali'];
            $this->hitung['tunggakan'] += $hasil['tersimpan']['tagihan'];

            $this->line(sprintf(
                '  %s: %d santri, %d wali baru, %d tagihan tunggakan (%d dilewati, %d bermasalah) — %s',
                $jenjang, $hasil['tersimpan']['santri'], $hasil['tersimpan']['wali'], $hasil['tersimpan']['tagihan'],
                $hasil['lewati'], $hasil['masalah'], $path,
            ));
        }
    }

    /** Satu baris CSV santri lama. */
    private function barisSantriLama(string $jenjang, int $tingkat, int $slot, array &$urutNis): array
    {
        // Tahun masuk mundur satu tahun per tingkat; NIS memakai dua digit
        // tahun itu, seperti NIS yang diterbitkan aplikasi.
        $tahunMasuk = (int) substr($this->ta, 0, 4) - ($tingkat - 1);
        $yy = (int) substr((string) $tahunMasuk, 2);
        $urutNis[$yy] = ($urutNis[$yy] ?? 100) + 1;
        $nis = sprintf('%02d%04d', $yy, $urutNis[$yy]);

        $lakiLaki = $slot % 2 === 0;
        $keluarga = self::KELUARGA_LAMA[$slot % count(self::KELUARGA_LAMA)];
        $nama = $this->namaSantri($lakiLaki, $slot % 20, $slot, $keluarga);

        $umur = self::UMUR_TINGKAT_SATU[$jenjang] + ($tingkat - 1);
        $tahunLahir = (int) substr($this->ta, 0, 4) - $umur;

        [$namaWali, $teleponWali] = $this->waliSantriLama($slot, $keluarga);

        // Tunggakan: sebagian saja, dan pola pembaginya berbeda-beda supaya ada
        // santri yang menunggak satu jenis dan ada yang beberapa sekaligus.
        return [
            'nis' => $nis,
            'nama' => $nama,
            'jenis_kelamin' => $lakiLaki ? 'L' : 'P',
            'kode_jenjang' => $jenjang,
            'tingkat' => (string) $tingkat,
            'tahun_ajaran' => $this->ta,
            'jalur' => match (true) {
                $slot % 13 === 0 => '005',
                $slot % 9 === 0 => '002',
                default => '001',
            },
            'wali_nama' => $namaWali,
            'wali_telepon' => $teleponWali,
            'tempat_lahir' => self::KOTA[$slot % count(self::KOTA)],
            'tanggal_lahir' => sprintf('%04d-%02d-%02d', $tahunLahir, ($slot % 12) + 1, ($slot % 28) + 1),
            'nisn' => sprintf('00%08d', 20000001 + $slot),
            'tunggakan_spp' => $slot % 3 === 0 ? '3000000' : '',
            'ket_tunggakan_spp' => $slot % 3 === 0 ? 'Tunggakan SPP 2 bulan terakhir' : '',
            'tunggakan_uang_pangkal' => $slot % 5 === 0 ? '7500000' : '',
            'ket_tunggakan_uang_pangkal' => $slot % 5 === 0 ? 'Sisa uang pangkal angkatan '.$tahunMasuk : '',
            'tunggakan_daftar_ulang' => $slot % 7 === 0 ? '1500000' : '',
            'ket_tunggakan_daftar_ulang' => $slot % 7 === 0 ? "Daftar ulang T.A {$this->ta}" : '',
            'tunggakan_lain' => $slot % 11 === 0 ? '450000' : '',
            'ket_tunggakan_lain' => $slot % 11 === 0 ? 'Seragam & buku paket' : '',
        ];
    }

    /**
     * Wali santri lama. Setiap slot ke-6 ditempelkan ke keluarga PPSB (kakak dari
     * calon yang sedang mendaftar) — itulah kasus yang paling perlu diuji: satu
     * dompet wali menaungi santri aktif DAN calon sekaligus. Sisanya wali sendiri,
     * dengan sebagian berbagi telepon supaya ada kakak-beradik di data lama juga.
     *
     * @return array{0:string,1:string}
     */
    private function waliSantriLama(int $slot, string $keluarga): array
    {
        if ($slot % 6 === 0) {
            $i = intdiv($slot, 6) % count(self::WALI_PPSB);
            $wali = Wali::where('telepon', $this->teleponPpsb($i))->first();
            if ($wali) {
                // Namanya HARUS sama persis: pemeta menolak telepon yang sudah
                // dipakai wali bernama lain (penjaga salah-ketik).
                return [$wali->nama, $wali->telepon];
            }
        }

        $grup = intdiv($slot * 8, 10); // ±80 wali untuk 100 santri → ada yang bersaudara

        return ["Bapak {$keluarga}", sprintf('0812%07d', 4000001 + $grup)];
    }

    /** Jenis biaya berpiutang untuk satu perilaku pada satu jenjang. */
    private function jenisTunggakan(string $jenjang, string $perilaku): string
    {
        // Disaring lewat PERILAKU, bukan kode tipe: kode tipe dibuat sendiri tiap
        // pesantren. kodeBerperilaku() juga memuat nama perilakunya sendiri,
        // sehingga data yang tipenya persis bernama perilaku tetap terbaca.
        $kode = JenisBiaya::query()
            ->where('kode_jenjang', $jenjang)->where('status', 'aktif')
            ->whereNotNull('kode_coa_piutang')
            ->whereIn('tipe', TipeBiaya::kodeBerperilaku($perilaku))
            ->orderBy('kode')->value('kode');

        if (! $kode) {
            $this->catatan[] = "Tidak ada jenis biaya berperilaku \"{$perilaku}\" berakun piutang untuk jenjang {$jenjang} — "
                .'tunggakan jenis itu tidak bisa dibuat.';
        }

        return (string) $kode;
    }

    private function tulisCsv(string $path, array $baris): void
    {
        $f = fopen($path, 'w');
        fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, array_keys($baris[0]), ',', '"', '\\');
        foreach ($baris as $b) {
            fputcsv($f, array_values($b), ',', '"', '\\');
        }
        fclose($f);
    }

    // ---------------- Bantuan ----------------

    /**
     * Nama santri = depan + tengah + keluarga. Ketiga bagiannya dipetik dengan
     * pembagi yang saling prima ($urut/20, $slot/7, keluarga/20 atau /40),
     * sehingga tak ada dua santri bernama sama di seluruh data uji.
     */
    private function namaSantri(bool $lakiLaki, int $urut, int $slot, string $keluarga): string
    {
        $depan = $lakiLaki ? self::NAMA_PUTRA[$urut] : self::NAMA_PUTRI[$urut];
        $tengah = self::NAMA_TENGAH[$slot % count(self::NAMA_TENGAH)];

        return "{$depan} {$tengah} {$keluarga}";
    }

    private function teleponPpsb(int $i): string
    {
        return sprintf('0811%07d', 3000001 + $i);
    }

    private function email(string $nama, string $keluarga): string
    {
        $slug = strtolower(preg_replace('/[^a-z]+/i', '.', trim($nama.' '.$keluarga)));

        return trim($slug, '.').'@contoh.test';
    }

    private function laporkan(): void
    {
        $h = $this->hitung;
        $this->newLine();
        $this->info('== Ringkasan ==');
        $this->table(['Yang dibuat', 'Jumlah'], [
            ['Wali PPSB baru', $h['wali'].($h['wali_disegarkan'] ? " ({$h['wali_disegarkan']} sudah ada, datanya disegarkan)" : '')],
            ['Calon santri', $h['calon'].($h['calon_lewati'] ? " ({$h['calon_lewati']} sudah ada, dilewati)" : '')],
            ['Pembayaran registrasi terverifikasi', $h['bayar']],
            ['Pembayaran registrasi menunggu verifikasi', $h['bayar_menunggu']],
            ['Calon bebas registrasi (tahap dilewati tanpa bayar)', $h['registrasi_bebas']],
            ['Tagihan uang pangkal + perlengkapan', $h['tagihan_uang_pangkal']],
            ['Santri aktif (impor santri lama)', $h['aktif']],
            ['Wali baru dari impor', $h['wali_aktif']],
            ['Tagihan tunggakan santri lama', $h['tunggakan']],
        ]);

        $sebaran = Santri::query()->selectRaw('status, count(*) c')->groupBy('status')->orderBy('status')->get();
        $this->info('Sebaran status seluruh santri di basis data:');
        foreach ($sebaran as $s) {
            $this->line(sprintf('  %-20s %d', $s->status, $s->c));
        }

        if ($this->catatan !== []) {
            $this->newLine();
            $this->warn('== Catatan / temuan ('.count($this->catatan).') ==');
            foreach (array_unique($this->catatan) as $c) {
                $this->line('  • '.$c);
            }
        }
    }
}
