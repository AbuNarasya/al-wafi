<?php

namespace Tests\Feature;

use App\Models\Jenjang;
use App\Models\Santri;
use App\Models\Wali;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Pengumuman "aplikasi masih demo" bagi wali yang masuk sebelum portal resmi dipakai (keputusan user 12 Nov 2026). */
class PortalMasaDemoTest extends TestCase
{
    use RefreshDatabase;

    private Wali $wali;

    protected function setUp(): void
    {
        parent::setUp();
        config(['portal.demo_sampai' => '2027-01-01']);
        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'jumlah_tingkat' => 3]);
        $this->wali = Wali::create(['kontak_utama' => 'ibu', 'nama_ibu' => 'Ibu Aminah', 'telepon_ibu' => '0811', 'email_ibu' => 'aminah@contoh.id',
            'nama' => 'Ibu Aminah', 'telepon' => '0811', 'status' => 'aktif']);
        Santri::create(['no_pendaftaran' => 'ZZD-1', 'nama' => 'Ahmad', 'jenis_kelamin' => 'L', 'kode_jenjang' => 'SMP', 'jalur' => 'reguler',
            'status' => 'aktif', 'id_wali' => $this->wali->id]);
    }

    private function sebagaiIbu(): static
    {
        return $this->actingAs($this->wali, 'wali')->withSession(['portal.peran' => 'ibu', 'portal.punya_sandi' => true]);
    }

    public function test_jendela_sekali_per_sesi_pita_di_setiap_halaman_tanpa_tanggal(): void
    {
        $this->travelTo('2026-11-12 10:00');
        $this->sebagaiIbu()->get(route('portal.beranda'))->assertOk()
            ->assertSee('data-jendela-demo', false)->assertSee('Aplikasi masih versi demo')->assertSee('data-pita-demo', false)
            ->assertDontSee('2027');
        $this->get(route('portal.akun'))->assertOk()->assertDontSee('data-jendela-demo', false)->assertSee('data-pita-demo', false);
    }

    public function test_hilang_sendiri_sejak_tanggal_resmi_atau_bila_dimatikan(): void
    {
        $this->travelTo('2027-01-01 00:01');
        $this->sebagaiIbu()->get(route('portal.beranda'))->assertOk()->assertDontSee('data-pita-demo', false)->assertDontSee('data-jendela-demo', false);

        $this->travelTo('2026-11-12 10:00');
        config(['portal.demo_sampai' => '']);
        $this->sebagaiIbu()->get(route('portal.beranda'))->assertOk()->assertDontSee('data-pita-demo', false);
    }

    public function test_tak_tampil_sebelum_masuk(): void
    {
        $this->travelTo('2026-11-12 10:00');
        $this->get(route('portal.masuk'))->assertOk()->assertDontSee('data-pita-demo', false);
    }
}
