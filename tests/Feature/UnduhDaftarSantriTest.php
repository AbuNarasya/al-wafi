<?php

namespace Tests\Feature;

use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
use App\Models\Level;
use App\Models\Santri;
use App\Models\SumberInformasi;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Models\Wali;
use App\Support\Export\BarisSantri;
use App\Support\Export\DatasetRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Js;
use Tests\TestCase;

/**
 * UNDUH DAFTAR SANTRI — tombol Unduh di tiap daftar + dataset di Export Data.
 *
 * Yang dijaga:
 *  • daftar Calon Santri & Siap Aktivasi punya berkasnya sendiri, sejajar
 *    Santri Aktif / Alumni / Santri Keluar yang sudah ada lebih dulu;
 *  • unduhan mengikuti PENYARING yang sedang aktif — orang menekan tombolnya
 *    setelah menyaring, dan berkas berisi semua orang bukan itu yang diminta;
 *  • unduhan TIDAK terpotong paginasi. Ini yang paling mudah lolos: daftarnya
 *    berhalaman 25 baris, jadi berkas yang ikut berpaginasi akan tampak benar
 *    selama datanya masih sedikit.
 */
class UnduhDaftarSantriTest extends TestCase
{
    use RefreshDatabase;

    private const TA = '2026/2027';

    private User $admin;

    private Wali $wali;

    protected function setUp(): void
    {
        parent::setUp();
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        TahunAjaran::create(['kode' => self::TA, 'status' => 'aktif', 'default_pendaftaran' => true]);
        JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler']);
        Jenjang::create(['kode' => 'SD', 'nama' => 'Sekolah Dasar', 'urutan' => 1, 'jumlah_tingkat' => 6]);
        Jenjang::create(['kode' => 'SMP', 'nama' => 'Sekolah Menengah', 'urutan' => 2, 'jumlah_tingkat' => 3]);

        $this->admin = User::create(['username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'tim_keuangan' => true, 'status' => 'aktif']);
        // Keluarga LENGKAP — ketiga peran terisi, supaya ketahuan bila unduhan
        // hanya membawa kontak utamanya saja.
        $this->wali = Wali::create([
            'nama' => 'Budi Santoso', 'telepon' => '081200001', 'kontak_utama' => 'ayah',
            'nik' => '3271010101900001', 'alamat' => 'Jl. Pesantren No. 1, Bogor',
            'nama_ayah' => 'Budi Santoso', 'telepon_ayah' => '081200001', 'email_ayah' => 'budi@contoh.id',
            'pekerjaan_ayah' => 'Wiraswasta', 'pendapatan_ayah' => 'juta_5_10',
            'nama_ibu' => 'Siti Aminah', 'telepon_ibu' => '081200002', 'email_ibu' => 'siti@contoh.id',
            'pekerjaan_ibu' => 'Guru', 'pendapatan_ibu' => 'di_bawah_5',
            'nama_wali' => 'Hasan Basri', 'telepon_wali' => '081200003', 'email_wali' => 'hasan@contoh.id',
            'pekerjaan_wali' => 'Pedagang', 'pendapatan_wali' => 'di_atas_25',
            'status' => 'aktif',
        ]);
    }

    /** Baris santri langsung — test ini menguji DAFTAR, bukan alur pendaftarannya. */
    private function santri(string $nama, string $status, string $jenjang = 'SD'): Santri
    {
        return Santri::create([
            'no_pendaftaran' => 'P'.str_pad((string) (Santri::count() + 1), 4, '0', STR_PAD_LEFT),
            'nama' => $nama, 'jenis_kelamin' => 'L', 'id_wali' => $this->wali->id,
            'status' => $status, 'tahun_ajaran' => self::TA, 'jalur' => 'reguler',
            'kode_jenjang' => $jenjang, 'tingkat' => 1,
        ]);
    }

    private function isi(string $lingkup, array $query = []): string
    {
        return $this->actingAs($this->admin)
            ->get(route('santri.unduh', $lingkup).'?'.http_build_query($query))
            ->assertOk()->streamedContent();
    }

    // ---- Tiga daftar yang diminta punya tombolnya ----

    public function test_tombol_unduh_ada_di_daftar_aktif_calon_dan_siap_aktivasi(): void
    {
        foreach (['aktif', 'calon', 'siap_aktivasi'] as $lingkup) {
            $this->actingAs($this->admin)->get(route('santri.'.$lingkup))
                ->assertOk()
                ->assertSee('Unduh:')
                // Alamatnya masuk sebagai literal JS di dalam x-data (lihat
                // komponen <x-unduh>), jadi garis miringnya ikut ter-escape.
                ->assertSee((string) Js::from(route('santri.unduh', $lingkup).'?'), false);
        }
    }

    public function test_unduh_santri_aktif_hanya_berisi_yang_aktif(): void
    {
        $this->santri('Santri Aktif', 'aktif');
        $this->santri('Masih Calon', 'calon');
        $this->santri('Sudah Alumni', 'alumni');

        $isi = $this->isi('aktif');
        $this->assertStringContainsString('Santri Aktif', $isi);
        $this->assertStringNotContainsString('Masih Calon', $isi);
        $this->assertStringNotContainsString('Sudah Alumni', $isi);
        $this->assertStringContainsString('Sisa Tagihan', $isi);
    }

    // ---- Kelengkapan kolom ----

    /** Seluruh kolom master santri ikut, bukan hanya yang tampak di layar. */
    public function test_unduhan_memuat_seluruh_kolom_master_santri(): void
    {
        SumberInformasi::create(['kode' => 'MEDSOS', 'nama' => 'Media Sosial', 'urutan' => 1, 'status' => 'aktif']);
        $this->santri('Lengkap', 'aktif')->update([
            'nis' => 'NIS-001', 'nisn' => '1234567890', 'tempat_lahir' => 'Bogor', 'tanggal_lahir' => '2015-03-17',
            'asal_sekolah' => 'SDN Contoh', 'alamat_sekolah_asal' => 'Jl. Sekolah No. 9',
            'kepala_sekolah_asal' => 'Pak Karto', 'cp_kepala_sekolah_asal' => '081300001',
            'wali_kelas_asal' => 'Bu Aminah', 'cp_wali_kelas_asal' => '081300002',
            'gelombang' => 2, 'sumber_informasi' => 'MEDSOS', 'sumber_informasi_lain' => 'Instagram',
            'nominal_spp' => '450000', 'keterangan_spp' => 'Potongan anak kedua',
        ]);

        $baris = BarisSantri::dari('aktif')[0];

        $this->assertSame('NIS-001', $baris['NIS']);
        $this->assertSame('1234567890', $baris['NISN']);
        $this->assertSame('Bogor', $baris['Tempat Lahir']);
        $this->assertSame('17/03/2015', $baris['Tanggal Lahir']);
        $this->assertSame('SDN Contoh', $baris['Asal Sekolah']);
        $this->assertSame('Jl. Sekolah No. 9', $baris['Alamat Sekolah Asal']);
        $this->assertSame('Pak Karto', $baris['Kepala Sekolah Asal']);
        $this->assertSame('081300001', $baris['CP Kepala Sekolah Asal']);
        $this->assertSame('Bu Aminah', $baris['Wali Kelas Asal']);
        $this->assertSame('081300002', $baris['CP Wali Kelas Asal']);
        $this->assertSame(2, $baris['Gelombang']);
        // Kode master di-resolve jadi NAMA — `MEDSOS` tak bercerita di luar aplikasi.
        $this->assertSame('Media Sosial', $baris['Sumber Informasi']);
        $this->assertSame('Instagram', $baris['Keterangan Sumber Informasi']);
        $this->assertSame('Potongan anak kedua', $baris['Keterangan SPP']);
        $this->assertSame('450000.00', $baris['Nominal SPP Khusus']);
        $this->assertSame('Sekolah Dasar', $baris['Jenjang']);
        $this->assertSame('Reguler', $baris['Jalur']);
        $this->assertSame(now()->format('d/m/Y'), $baris['Tanggal Didaftarkan']);
    }

    /** Data KETIGA peran keluarga, bukan cuma kontak utamanya. */
    public function test_unduhan_memuat_data_ayah_ibu_dan_wali(): void
    {
        $this->santri('Lengkap', 'aktif');

        $baris = BarisSantri::dari('aktif')[0];

        // Kontak utama = turunan peran yang dipilih; dinamai tegas supaya tak
        // tertukar dengan kolom "… Wali" yang berarti wali bukan orang tua.
        $this->assertSame('Ayah', $baris['Kontak Utama']);
        $this->assertSame('Budi Santoso', $baris['Nama Kontak Utama']);
        $this->assertSame('081200001', $baris['Telepon Kontak Utama']);
        $this->assertSame('3271010101900001', $baris['NIK Keluarga']);
        $this->assertSame('Jl. Pesantren No. 1, Bogor', $baris['Alamat']);

        foreach ([
            ['Ayah', 'Budi Santoso', '081200001', 'budi@contoh.id', 'Wiraswasta', 'Rp 5–10 juta'],
            ['Ibu', 'Siti Aminah', '081200002', 'siti@contoh.id', 'Guru', '< Rp 5 juta'],
            ['Wali', 'Hasan Basri', '081200003', 'hasan@contoh.id', 'Pedagang', '> Rp 25 juta'],
        ] as [$peran, $nama, $telepon, $email, $kerja, $pendapatan]) {
            $this->assertSame($nama, $baris["Nama {$peran}"]);
            $this->assertSame($telepon, $baris["Telepon {$peran}"]);
            $this->assertSame($email, $baris["Email {$peran}"]);
            $this->assertSame($kerja, $baris["Pekerjaan {$peran}"]);
            // Rentang pendapatan disebut, bukan kodenya (`juta_5_10`).
            $this->assertSame($pendapatan, $baris["Pendapatan {$peran}"]);
        }
    }

    /** Kolom lengkap harus ikut di berkas yang benar-benar diunduh, bukan cuma di array. */
    public function test_berkas_unduhan_membawa_kolom_keluarga(): void
    {
        $this->santri('Lengkap', 'aktif');

        $isi = $this->isi('aktif');
        foreach (['Nama Ayah', 'Nama Ibu', 'Nama Wali', 'Pendapatan Ibu', 'NIK Keluarga', 'Alamat'] as $kolom) {
            $this->assertStringContainsString($kolom, $isi);
        }
        $this->assertStringContainsString('Siti Aminah', $isi);
        $this->assertStringContainsString('Hasan Basri', $isi);
    }

    public function test_unduh_calon_memuat_seluruh_tahap_penerimaan(): void
    {
        $this->santri('Baru Daftar', 'calon');
        $this->santri('Lolos Medcheck', 'lolos_kesehatan');
        $this->santri('Tidak Lulus', 'tidak_lulus');
        $this->santri('Sudah Aktif', 'aktif');
        // Yang sudah tuntas berkasnya berdaftar sendiri — tak ikut di sini.
        $this->santri('Siap Diaktifkan', 'siap_aktivasi');

        $isi = $this->isi('calon');
        foreach (['Baru Daftar', 'Lolos Medcheck', 'Tidak Lulus'] as $nama) {
            $this->assertStringContainsString($nama, $isi);
        }
        $this->assertStringNotContainsString('Sudah Aktif', $isi);
        $this->assertStringNotContainsString('Siap Diaktifkan', $isi);
    }

    public function test_unduh_siap_aktivasi_hanya_berisi_yang_siap(): void
    {
        $this->santri('Siap Diaktifkan', 'siap_aktivasi');
        $this->santri('Baru Daftar', 'calon');
        $this->santri('Sudah Aktif', 'aktif');

        $isi = $this->isi('siap_aktivasi');
        $this->assertStringContainsString('Siap Diaktifkan', $isi);
        $this->assertStringNotContainsString('Baru Daftar', $isi);
        $this->assertStringNotContainsString('Sudah Aktif', $isi);
    }

    // ---- Penyaring & paginasi ----

    public function test_unduh_mengikuti_penyaring_yang_sedang_aktif(): void
    {
        $this->santri('Anak SD', 'aktif', 'SD');
        $this->santri('Anak SMP', 'aktif', 'SMP');

        $perJenjang = $this->isi('aktif', ['jenjang' => 'SMP']);
        $this->assertStringContainsString('Anak SMP', $perJenjang);
        $this->assertStringNotContainsString('Anak SD', $perJenjang);

        $perCarian = $this->isi('aktif', ['q' => 'Anak SD']);
        $this->assertStringContainsString('Anak SD', $perCarian);
        $this->assertStringNotContainsString('Anak SMP', $perCarian);
    }

    /** Daftarnya berpaginasi 25; berkasnya tidak boleh ikut terpotong. */
    public function test_unduh_memuat_seluruh_baris_bukan_satu_halaman(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->santri('Santri Ke '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'aktif');
        }

        $isi = $this->isi('aktif');
        $this->assertSame(31, substr_count(trim($isi), "\n") + 1, 'header + 30 baris');
        $this->assertStringContainsString('Santri Ke 30', $isi);
    }

    // ---- Halaman Export Data ----

    public function test_dataset_calon_dan_siap_aktivasi_terdaftar_di_export_data(): void
    {
        $kunci = collect(DatasetRegistry::datasets())->pluck('key')->all();
        $this->assertContains('calon-santri', $kunci);
        $this->assertContains('siap-aktivasi', $kunci);

        $this->santri('Baru Daftar', 'calon');
        $this->santri('Siap Diaktifkan', 'siap_aktivasi');

        $calon = (new DatasetRegistry)->rows('calon-santri');
        $this->assertCount(1, $calon);
        $this->assertSame('Baru Daftar', $calon[0]['Nama']);
        $this->assertSame('Calon', $calon[0]['Status']);

        $siap = (new DatasetRegistry)->rows('siap-aktivasi');
        $this->assertCount(1, $siap);
        $this->assertSame('Siap Diaktifkan', $siap[0]['Nama']);
        $this->assertSame('Siap Diaktifkan', $siap[0]['Status']);

        // Keduanya dapat diunduh dari halaman Export Data.
        foreach (['calon-santri', 'siap-aktivasi'] as $key) {
            $this->actingAs($this->admin)->get(route('export.dataset', $key))->assertOk();
        }
    }

    /** Yang sudah ada sebelumnya tak boleh ikut berubah saat barisnya dipindah. */
    public function test_dataset_lama_tetap_utuh(): void
    {
        $this->santri('Sudah Alumni', 'alumni')->update(['tanggal_lulus' => '2026-06-30']);

        $alumni = (new DatasetRegistry)->rows('alumni');
        $this->assertCount(1, $alumni);
        $this->assertSame('30/06/2026', $alumni[0]['Tanggal Lulus']);
        $this->assertArrayHasKey('Tingkat Akhir', $alumni[0]);

        // Kolom khusus alumni tak boleh bocor ke daftar lain.
        $this->santri('Santri Aktif', 'aktif');
        $this->assertArrayNotHasKey('Tanggal Lulus', (new DatasetRegistry)->rows('santri-aktif')[0]);
    }
}
