<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
use App\Models\Level;
use App\Models\Santri;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Models\Wali;
use App\Support\Export\Exporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PEMILIH KOLOM UNDUHAN — berlaku untuk SELURUH unduhan aplikasi.
 *
 * Mekanismenya sengaja ditaruh di Exporter, bukan di tiap controller: semua
 * unduhan berakhir di sana, jadi unduhan yang ditambahkan nanti ikut kebagian
 * tanpa perlu diingat. Test ini karena itu menguji TIGA controller berbeda
 * (daftar Santri, Export Data, Dashboard) — kalau ketiganya lulus lewat satu
 * mesin yang sama, sisanya ikut.
 *
 * Yang dijaga:
 *  • `?kolom=daftar` menyebutkan kolom yang tersedia (untuk mengisi panelnya);
 *  • `?kolom[]=…` memangkas berkas, dengan urutan mengikuti SUMBER;
 *  • tanpa `kolom`, berkasnya utuh seperti sebelum fasilitas ini ada;
 *  • nama kolom yang tak dikenal tidak pernah menghasilkan berkas KOSONG.
 */
class PilihKolomUnduhanTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        TahunAjaran::create(['kode' => '2026/2027', 'status' => 'aktif', 'default_pendaftaran' => true]);
        JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler']);
        Jenjang::create(['kode' => 'SD', 'nama' => 'Sekolah Dasar', 'urutan' => 1, 'jumlah_tingkat' => 6]);
        $this->admin = User::create(['username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'tim_keuangan' => true, 'status' => 'aktif']);

        $wali = Wali::create(['nama' => 'Budi', 'telepon' => '08123', 'kontak_utama' => 'ayah',
            'nama_ayah' => 'Budi', 'telepon_ayah' => '08123', 'status' => 'aktif']);
        Santri::create(['no_pendaftaran' => 'P0001', 'nis' => 'NIS-001', 'nama' => 'Ahmad Fauzi',
            'jenis_kelamin' => 'L', 'id_wali' => $wali->id, 'status' => 'aktif',
            'tahun_ajaran' => '2026/2027', 'jalur' => 'reguler', 'kode_jenjang' => 'SD', 'tingkat' => 1]);
    }

    private function unduh(string $url, array $query = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->get($url.'?'.http_build_query($query));
    }

    private function csv(string $url, array $query = []): string
    {
        return $this->unduh($url, $query)->assertOk()->streamedContent();
    }

    /** Baris pertama CSV = header; BOM UTF-8 di depan dibuang. */
    private function header(string $csv): array
    {
        return str_getcsv(trim(strtok(ltrim($csv, "\xEF\xBB\xBF"), "\n")));
    }

    // ---- Bertanya kolom apa saja yang ada ----

    public function test_daftar_kolom_dijawab_dari_alamat_unduhan_yang_sama(): void
    {
        $r = $this->unduh(route('santri.unduh', 'aktif'), ['kolom' => Exporter::MINTA_DAFTAR])->assertOk();

        $kolom = $r->json('kolom');
        $this->assertContains('NIS', $kolom);
        $this->assertContains('Nama', $kolom);
        $this->assertContains('Nama Ibu', $kolom);
        // Hanya NAMA kolomnya — datanya tidak ikut terkirim.
        $this->assertStringNotContainsString('Ahmad Fauzi', $r->getContent());
    }

    public function test_daftar_kolom_juga_dijawab_export_data_dan_dashboard(): void
    {
        BusinessUnit::create(['kode_unit' => 'U1', 'nama_unit' => 'Unit Satu']);
        CoaGroup::create(['kode_grup' => 'G1', 'nama_grup' => 'Kas']);
        CoaDetail::create(['kode_coa' => '1.G1.KAS', 'nama_coa' => 'Kas Besar', 'kode_grup' => 'G1', 'jenis_saldo' => 'debet']);
        BankAccount::create(['kode_coa' => '1.G1.KAS', 'nama_rekening' => 'Kas Besar', 'jenis_rekening' => 'tunai', 'status' => 'aktif']);

        $this->assertSame(
            ['Kode Unit', 'Nama Unit', 'Status'],
            $this->unduh(route('export.dataset', 'business-units'), ['kolom' => Exporter::MINTA_DAFTAR])->assertOk()->json('kolom'),
        );
        $this->assertContains(
            'Saldo',
            $this->unduh(url('dashboard/export/kas-rekening'), ['kolom' => Exporter::MINTA_DAFTAR])->assertOk()->json('kolom'),
        );
    }

    // ---- Memangkas berkasnya ----

    public function test_hanya_kolom_yang_diminta_yang_terbit(): void
    {
        $csv = $this->csv(route('santri.unduh', 'aktif'), ['kolom' => ['Nama', 'Nama Ibu', 'NIS']]);

        // Urutan mengikuti SUMBER (NIS lebih dulu dari Nama), bukan urutan centang.
        $this->assertSame(['NIS', 'Nama', 'Nama Ibu'], $this->header($csv));
        $this->assertStringContainsString('Ahmad Fauzi', $csv);
        $this->assertStringNotContainsString('No. Pendaftaran', $csv);
    }

    public function test_berlaku_juga_di_export_data(): void
    {
        BusinessUnit::create(['kode_unit' => 'U1', 'nama_unit' => 'Unit Satu']);

        $csv = $this->csv(route('export.dataset', 'business-units'), ['kolom' => ['Nama Unit']]);
        $this->assertSame(['Nama Unit'], $this->header($csv));
        $this->assertStringContainsString('Unit Satu', $csv);
        $this->assertStringNotContainsString('U1', $csv);
    }

    public function test_format_lain_ikut_memangkas(): void
    {
        foreach (['xlsx', 'pdf'] as $format) {
            $this->unduh(route('santri.unduh', 'aktif'), ['format' => $format, 'kolom' => ['Nama']])->assertOk();
        }
    }

    // ---- Perilaku bawaan & jaring pengaman ----

    public function test_tanpa_parameter_kolom_berkasnya_tetap_utuh(): void
    {
        $header = $this->header($this->csv(route('santri.unduh', 'aktif')));

        $this->assertContains('No. Pendaftaran', $header);
        $this->assertContains('Nama Ayah', $header);
        $this->assertGreaterThan(40, count($header), 'seluruh kolom master ikut');
    }

    /**
     * Tautan lama (atau kolom yang berganti nama) tak boleh menghasilkan berkas
     * kosong — kosong tanpa sebab yang terlihat jauh lebih menyesatkan daripada
     * berkas yang kelebihan kolom.
     */
    public function test_kolom_tak_dikenal_tidak_menghasilkan_berkas_kosong(): void
    {
        $header = $this->header($this->csv(route('santri.unduh', 'aktif'), ['kolom' => ['Kolom Yang Sudah Tiada']]));
        $this->assertContains('Nama', $header);

        // Sebagian dikenal → yang dikenal saja yang dipakai.
        $this->assertSame(
            ['Nama'],
            $this->header($this->csv(route('santri.unduh', 'aktif'), ['kolom' => ['Nama', 'Kolom Yang Sudah Tiada']])),
        );
    }

    public function test_panel_kolom_muncul_di_halaman_unduhan(): void
    {
        foreach ([route('santri.aktif'), route('export.index')] as $halaman) {
            $this->actingAs($this->admin)->get($halaman)->assertOk()
                ->assertSee('unduhKolom(', false)
                ->assertSee('Data Lengkap');
        }
    }
}
