<?php

namespace Tests\Feature;

use App\Models\BusinessUnit;
use App\Models\CompanySettings;
use App\Models\HakAksesModul;
use App\Models\Level;
use App\Models\User;
use App\Support\Akses;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GRUP MENU WAFI MART (19 Okt 2026): layar toko pindah dari KEUANGAN; layar persediaan
 * tampil di dua grup dan grup yang terbuka mengikuti tempat menu diklik; laporan keuangan
 * tersaring unit Wafi Mart.
 */
class MenuWafiMartTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Staf', 'max_transaksi' => null]);
        BusinessUnit::create(['kode_unit' => 'UWM', 'nama_unit' => 'Wafi Mart']);
        $this->admin = User::create(['username' => 'adm', 'nama' => 'Adm', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif'])->fresh();
    }

    /** @return array<string, list<string>> grup → label item */
    private function pohon(): array
    {
        $hasil = [];
        foreach (Navigation::tree() as $g) {
            foreach ($g['subs'] as $s) {
                foreach ($s['items'] as $i) {
                    $hasil[$g['group']][] = $i['label'];
                }
            }
        }

        return $hasil;
    }

    private function grupTerbuka(string $url): string
    {
        $html = $this->actingAs($this->admin)->get($url)->assertOk()->getContent();
        preg_match('/x-data="\{ open: (.*?), openSub/', $html, $m);

        return trim(html_entity_decode($m[1] ?? ''), "'\"");
    }

    public function test_grup_wafi_mart_memuat_seluruh_layar_toko(): void
    {
        $this->actingAs($this->admin);
        $pohon = $this->pohon();

        foreach (['Toko & Gudang (Lokasi)', 'Kategori Barang', 'Impor Barang & Stok Awal', 'Data Barang & Stok', 'Transfer Antar Gudang',
            'Stok Opname', 'Kasir (POS)', 'Shift Kasir & Setoran', 'Pencairan Belanja Kartu', 'Laporan Toko', 'Laba Rugi Wafi Mart'] as $label) {
            $this->assertContains($label, $pohon['WAFI MART'], $label);
        }
        // Layar khusus toko keluar dari KEUANGAN; layar persediaan bersama tetap di sana.
        foreach (['Kasir (POS)', 'Shift Kasir & Setoran', 'Pencairan Belanja Kartu', 'Kategori Barang', 'Merk Barang'] as $label) {
            $this->assertNotContains($label, $pohon['KEUANGAN'], $label);
        }
        $this->assertContains('Persediaan', $pohon['KEUANGAN']);
        $this->assertContains('Kartu Santri (RFID)', $pohon['DATA SISWA/SANTRI']);
    }

    public function test_grup_yang_terbuka_mengikuti_tempat_menu_diklik(): void
    {
        $this->assertSame('WAFI MART', $this->grupTerbuka('/inventory?nav=wafi-mart'));
        $this->assertSame('WAFI MART', $this->grupTerbuka('/inventory'));            // diingat dari klik sebelumnya
        $this->assertSame('KEUANGAN', $this->grupTerbuka('/inventory?nav=keuangan'));
        $this->assertSame('WAFI MART', $this->grupTerbuka('/shift-kasir'));            // hanya ada di WAFI MART
    }

    public function test_laporan_tersaring_unit_wafi_mart(): void
    {
        $this->actingAs($this->admin);
        $href = fn () => collect(Navigation::tree())->firstWhere('group', 'WAFI MART')['subs'];
        $cari = function () use ($href) {
            foreach ($href() as $s) {
                foreach ($s['items'] as $i) {
                    if ($i['label'] === 'Laba Rugi Wafi Mart') {
                        return $i['href'];
                    }
                }
            }
        };

        $this->assertSame('/company-settings', $cari());   // unit belum dipilih → ke Pengaturan
        CompanySettings::updateOrCreate(['id' => 1], ['nama_perusahaan' => 'X', 'jenis_perusahaan' => 'Yayasan', 'mata_uang' => 'IDR',
            'periode_awal_pembukuan' => '2026-01-01', 'bulan_awal_anggaran' => 1, 'kode_unit_wafimart' => 'UWM']);
        \Illuminate\Support\Once::flush();
        $this->assertSame('/reports/laba-rugi?kode_unit=UWM&nav=wafi-mart', $cari());
        $this->assertSame('WAFI MART', $this->grupTerbuka('/reports/laba-rugi?kode_unit=UWM&nav=wafi-mart'));
    }

    public function test_pengguna_tanpa_hak_toko_tak_melihat_grupnya(): void
    {
        $u = User::create(['username' => 'kas', 'nama' => 'Kas', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif'])->fresh();
        HakAksesModul::create(['id_pengguna' => $u->id_pengguna, 'kode_modul' => 'cash-in', 'lihat' => true, 'buat' => false, 'ubah' => false, 'hapus' => false, 'menu' => true]);
        Akses::lupakan();
        $this->actingAs($u);
        $this->assertArrayNotHasKey('WAFI MART', $this->pohon());

        HakAksesModul::create(['id_pengguna' => $u->id_pengguna, 'kode_modul' => 'pos', 'lihat' => true, 'buat' => true, 'ubah' => false, 'hapus' => false, 'menu' => true]);
        Akses::lupakan();
        $this->assertSame(['Kasir (POS)'], $this->pohon()['WAFI MART']);
    }
}
