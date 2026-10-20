<?php

namespace Tests\Feature;

use App\Models\HakAksesModul;
use App\Models\Level;
use App\Models\User;
use App\Support\Akses;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * JATI DIRI & SAKLAR MODUL PER INSTANS (config/instans.php, keputusan user 20 Okt 2026).
 * Instans Wafi Mart: modul santri/PPSB/kependidikan/portal wali TERTUTUP bagi siapa pun (404),
 * hilang dari menu & matriks; ERP Al Wafi (bawaan) tak berubah.
 */
class InstansTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Staf', 'max_transaksi' => null]);
        $this->admin = User::create(['username' => 'adm', 'nama' => 'Adm', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif'])->fresh();
    }

    private function instans(string $kode, string $matiTambahan = ''): void
    {
        config(['instans.kode' => $kode, 'instans.modul_mati_tambahan' => $matiTambahan]);
        Akses::lupakan();
    }

    /** @return list<string> */
    private function grupMenu(): array
    {
        $this->actingAs($this->admin);

        return array_column(Navigation::tree(), 'group');
    }

    private function labelMenu(): array
    {
        $label = [];
        foreach (Navigation::tree() as $g) {
            foreach ($g['subs'] as $s) {
                $label = [...$label, ...array_column($s['items'], 'label')];
            }
        }

        return $label;
    }

    public function test_instans_bawaan_al_wafi_tak_berubah(): void
    {
        $this->assertSame('Al Wafi ERP', \App\Support\Instans::nama());
        $this->get('/login')->assertOk()->assertSee('Al Wafi ERP')->assertSee('/manifest.json', false);
        $this->actingAs($this->admin)->get('/kesantrian/santri')->assertOk();
        $this->assertContains('DATA SISWA/SANTRI', $this->grupMenu());
        $this->assertContains('Rekening Portal Wali', $this->labelMenu());
    }

    public function test_instans_wafi_mart_menutup_modul_santri_bagi_admin_sekalipun(): void
    {
        $this->instans('wafimart');

        $this->get('/login')->assertOk()->assertSee('Wafi Mart ERP')->assertDontSee('Al Wafi ERP')->assertSee('/manifest-wafimart.json', false);
        foreach (['/kesantrian/santri', '/ppsb/calon-santri', '/datawali', '/kartu-santri', '/pencairan-kartu', '/tarik-tunai', '/santri-manual', '/rekening-portal'] as $url) {
            $this->actingAs($this->admin)->get($url)->assertNotFound();
        }
        $this->get('/wali')->assertNotFound();          // Portal Wali, tanpa login pun
        $this->post('/midtrans/notifikasi')->assertNotFound();

        // Yang tetap hidup: keuangan & toko.
        $this->actingAs($this->admin)->get('/inventory')->assertOk();
        $this->actingAs($this->admin)->get('/cash-out')->assertOk();

        $grup = $this->grupMenu();
        foreach (['DATA SISWA/SANTRI', 'PPSB', 'KEPENDIDIKAN', 'TAGIHAN LAIN-LAIN'] as $g) {
            $this->assertNotContains($g, $grup, $g);
        }
        $this->assertContains('WAFI MART', $grup);
        $label = $this->labelMenu();
        foreach (['Kartu Santri (RFID)', 'Pencairan Belanja Kartu', 'Penarikan Tunai Kegiatan', 'Rekening Portal Wali', 'Input Manual Santri Aktif', 'Jenis Biaya'] as $l) {
            $this->assertNotContains($l, $label, $l);
        }
        $this->assertContains('Kasir (POS)', $label);

        // Murni minimarket: tanpa laporan & dana khas yayasan, tanpa menu kembar, tanpa laporan "… Wafi Mart".
        foreach (['Perubahan Aset Neto', 'Dana Terikat', 'Laporan Pertanggungjawaban Dana', 'Laba Rugi Wafi Mart', 'Neraca Saldo Wafi Mart'] as $l) {
            $this->assertNotContains($l, $label, $l);
        }
        $keuangan = [];
        foreach (Navigation::tree() as $g) {
            if ($g['group'] === 'KEUANGAN') {
                foreach ($g['subs'] as $s) {
                    $keuangan = [...$keuangan, ...array_column($s['items'], 'url')];
                }
            }
        }
        foreach (['/inventory', '/purchase-orders', '/penerimaan-barang', '/lokasi-persediaan'] as $u) {
            $this->assertNotContains($u, $keuangan, $u);
        }
        $this->assertContains('Data Barang & Stok', $label);
        $this->assertContains('Penghasilan Komprehensif', $label);
        $this->actingAs($this->admin)->get('/reports/perubahan-aset-neto')->assertNotFound();
        $this->actingAs($this->admin)->get('/dana')->assertNotFound();

        // Impor: tanpa jenis santri; Pengaturan Perusahaan: tanpa isian dompet/jajan santri.
        $impor = $this->actingAs($this->admin)->get('/impor-data-awal')->assertOk()->getContent();
        $this->assertStringNotContainsString('santri-lama', $impor);
        $this->assertStringContainsString('barang-toko', $impor);
        $this->actingAs($this->admin)->get('/company-settings')->assertOk()
            ->assertDontSee('Dompet Santri')->assertDontSee('Batas belanja kartu')->assertDontSee('Unit Bisnis Wafi Mart');
    }

    public function test_matriks_hak_akses_tanpa_modul_mati_dan_haknya_tak_terhapus(): void
    {
        $u = User::create(['username' => 'kas', 'nama' => 'Kas', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif'])->fresh();
        HakAksesModul::create(['id_pengguna' => $u->id_pengguna, 'kode_modul' => 'santri', 'lihat' => true, 'buat' => false, 'ubah' => false, 'hapus' => false, 'menu' => true]);
        $this->instans('wafimart');

        $html = $this->actingAs($this->admin)->get(route('hak_akses.edit', $u->id_pengguna))->assertOk()->getContent();
        $this->assertStringNotContainsString('name="hak[santri][lihat]"', $html);
        $this->assertStringContainsString('name="hak[pos][lihat]"', $html);

        $this->actingAs($this->admin)->put(route('hak_akses.update', $u->id_pengguna), ['hak' => ['pos' => ['lihat' => 1, 'buat' => 1, 'menu' => 1]]])
            ->assertRedirect();
        $this->assertEqualsCanonicalizing(['pos', 'santri'], HakAksesModul::where('id_pengguna', $u->id_pengguna)->pluck('kode_modul')->all());
    }

    public function test_wafimart_terpisah_menghapus_fungsi_toko_dari_al_wafi(): void
    {
        config(['instans.wafimart_terpisah' => true]);
        Akses::lupakan();

        foreach (['/pos', '/shift-kasir', '/laporan-toko', '/kategori-barang', '/merk-barang'] as $url) {
            $this->actingAs($this->admin)->get($url)->assertNotFound();
        }
        // Sisi dompet santri & persediaan pesantren tetap.
        foreach (['/kartu-santri', '/pencairan-kartu', '/inventory', '/purchase-orders', '/kesantrian/santri'] as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk();
        }
        $this->assertNotContains('WAFI MART', $this->grupMenu());
        $label = $this->labelMenu();
        foreach (['Kartu Santri (RFID)', 'Pencairan Belanja Kartu', 'Persediaan'] as $l) {
            $this->assertContains($l, $label, $l);
        }
        $this->assertNotContains('Kasir (POS)', $label);

        // Instans Wafi Mart tak terpengaruh saklar ini.
        $this->instans('wafimart');
        config(['instans.wafimart_terpisah' => true]);
        $this->actingAs($this->admin)->get('/pos')->assertOk();
    }

    public function test_modul_mati_tambahan_lewat_env_di_al_wafi(): void
    {
        $this->instans('alwafi', 'pos, shift-kasir');

        $this->actingAs($this->admin)->get('/pos')->assertNotFound();
        $this->actingAs($this->admin)->get('/shift-kasir')->assertNotFound();
        $this->actingAs($this->admin)->get('/kesantrian/santri')->assertOk();
        $this->assertNotContains('Kasir (POS)', $this->labelMenu());
    }
}
