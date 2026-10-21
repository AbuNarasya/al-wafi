<?php

namespace Tests\Feature;

use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
use App\Models\Santri;
use App\Models\TahunAjaran;
use App\Models\Wali;
use App\Services\PortalWali\MenuPortal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Menu utama portal pilihan wali — maks 7 ikon + "Menu Lengkap" berkelompok (21 Okt 2026). */
class MenuPortalTest extends TestCase
{
    use RefreshDatabase;

    private Wali $wali;

    protected function setUp(): void
    {
        parent::setUp();
        Jenjang::create(['kode' => 'J002', 'nama' => 'SMP', 'urutan' => 2, 'jumlah_tingkat' => 3]);
        TahunAjaran::create(['kode' => '2026/2027', 'status' => 'aktif']);
        JalurPendaftaran::create(['kode' => '001', 'nama' => 'Reguler']);
        $this->wali = Wali::create(['kontak_utama' => 'ibu', 'nama_ibu' => 'Ibu Aminah', 'telepon_ibu' => '0811', 'email_ibu' => 'aminah@contoh.id',
            'nama' => 'Ibu Aminah', 'telepon' => '0811', 'status' => 'aktif']);
    }

    private function sebagaiIbu(): static
    {
        return $this->actingAs($this->wali, 'wali')->withSession(['portal.peran' => 'ibu', 'portal.punya_sandi' => true]);
    }

    private function anakAktif(): void
    {
        Santri::create(['no_pendaftaran' => 'L-1', 'id_wali' => $this->wali->id, 'nama' => 'Ahmad', 'jenis_kelamin' => 'L', 'kode_jenjang' => 'J002',
            'tingkat' => 1, 'tahun_ajaran' => '2026/2027', 'jalur' => '001', 'status' => 'aktif']);
    }

    public function test_bawaan_tujuh_ikon_plus_menu_lengkap_dan_menu_dompet_hanya_bila_berdompet(): void
    {
        // Keluarga tanpa anak aktif: menu dompet gugur, diisi menu tersedia lain — tetap 7.
        $tanpa = MenuPortal::pilihan($this->wali, 'ibu');
        $this->assertCount(7, $tanpa);
        $this->assertNotContains('mutasi_anak', $tanpa);

        $this->anakAktif();
        $this->assertSame(MenuPortal::BAWAAN, MenuPortal::pilihan($this->wali, 'ibu'));
        $this->sebagaiIbu()->get(route('portal.beranda'))->assertOk()->assertSee('Menu Lengkap')->assertSee('Riwayat klinik')
            ->assertDontSee('Pindah saldo');

        $this->sebagaiIbu()->get(route('portal.menu'))->assertOk()
            ->assertSeeInOrder(['Keuangan', 'Pindah saldo', 'Administrasi', 'Data wali &amp; santri', 'Lainnya', 'Riwayat klinik'], false)
            ->assertDontSee('>Akademik<', false); // kelompok tanpa menu tak ditampilkan
    }

    public function test_wali_mengatur_urutan_maks_tujuh_dan_kembali_ke_bawaan(): void
    {
        $this->anakAktif();
        $this->sebagaiIbu()->post(route('portal.menu.simpan'), ['menu' => ['pindah_saldo', 'tagihan', 'klinik']])->assertRedirect(route('portal.beranda'));
        $this->assertSame(['pindah_saldo', 'tagihan', 'klinik'], MenuPortal::pilihan($this->wali, 'ibu'));
        $this->assertSame(MenuPortal::BAWAAN, MenuPortal::pilihan($this->wali, 'ayah'), 'pilihan per orang');
        $this->sebagaiIbu()->get(route('portal.beranda'))->assertSeeInOrder(['Pindah saldo', 'Tagihan', 'Riwayat klinik', 'Menu Lengkap']);

        $delapan = array_slice(array_keys(MenuPortal::KATALOG), 0, 8);
        $this->sebagaiIbu()->post(route('portal.menu.simpan'), ['menu' => $delapan])->assertSessionHas('error');
        $this->sebagaiIbu()->post(route('portal.menu.simpan'), ['menu' => ['tidak_ada']])->assertSessionHas('error');

        $this->sebagaiIbu()->post(route('portal.menu.simpan'), ['bawaan' => 1])->assertSessionHas('status');
        $this->assertSame(MenuPortal::BAWAAN, MenuPortal::pilihan($this->wali, 'ibu'));
    }
}
