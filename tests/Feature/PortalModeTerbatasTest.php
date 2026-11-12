<?php

namespace Tests\Feature;

use App\Models\HakAksesModul;
use App\Models\Jenjang;
use App\Models\Level;
use App\Models\NotifikasiWali;
use App\Models\Santri;
use App\Models\User;
use App\Models\Wali;
use App\Services\PortalWali\KabarWali;
use App\Support\Akses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MODE TERBATAS PORTAL WALI masa uji coba (keputusan user 12 Nov 2026): wali biasa hanya data, berkas, akun, &
 * alur PPSB; kabar hanya soal berkas & data; keluarga bertanda akun uji tetap bisa semuanya.
 */
class PortalModeTerbatasTest extends TestCase
{
    use RefreshDatabase;

    private Wali $wali;

    private Santri $aktif;

    private Santri $calon;

    protected function setUp(): void
    {
        parent::setUp();
        config(['portal.terbatas' => true]);
        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'jumlah_tingkat' => 3]);
        $this->wali = Wali::create(['kontak_utama' => 'ibu', 'nama_ibu' => 'Ibu Aminah', 'telepon_ibu' => '0811', 'email_ibu' => 'aminah@contoh.id',
            'nama' => 'Ibu Aminah', 'telepon' => '0811', 'status' => 'aktif']);
        $this->aktif = Santri::create(['no_pendaftaran' => 'ZZT-1', 'nama' => 'Ahmad', 'jenis_kelamin' => 'L', 'kode_jenjang' => 'SMP',
            'jalur' => 'reguler', 'status' => 'aktif', 'id_wali' => $this->wali->id]);
        $this->calon = Santri::create(['no_pendaftaran' => 'ZZT-2', 'nama' => 'Fatimah', 'jenis_kelamin' => 'P', 'kode_jenjang' => 'SMP',
            'jalur' => 'reguler', 'status' => 'calon', 'id_wali' => $this->wali->id]);
    }

    private function sebagaiIbu(): static
    {
        return $this->actingAs($this->wali, 'wali')->withSession(['portal.peran' => 'ibu', 'portal.punya_sandi' => true]);
    }

    /** Koreksi user 12 Nov 2026: tampilan & menu LENGKAP dan bisa dibuka; hanya TINDAKANNYA yang dikunci. */
    public function test_semua_halaman_bisa_dibuka_tetapi_tindakan_dikunci(): void
    {
        $this->sebagaiIbu()->get(route('portal.beranda'))->assertOk()->assertSee(route('portal.tagihan'), false)
            ->assertSee('data-pita-demo', false)->assertSee('Semua menu bisa dilihat');

        foreach ([route('portal.tagihan'), route('portal.dompet'), route('portal.perizinan'), route('portal.kabar'), route('portal.akun'),
            route('portal.anak', $this->aktif->id), route('portal.mundur', $this->aktif->id), route('portal.data'),
            route('portal.berkas', $this->aktif->id)] as $url) {
            $this->sebagaiIbu()->get($url)->assertOk();
        }

        $pesan = \App\Services\PortalWali\PortalTerbatas::pesan();
        foreach ([route('portal.online.dompet'), route('portal.perizinan.kirim'), route('portal.tarik_tunai.ajukan'),
            route('portal.mundur.ajukan', $this->aktif->id), route('portal.dompet.tabungan')] as $url) {
            $this->sebagaiIbu()->from(route('portal.tagihan'))->post($url)->assertRedirect(route('portal.tagihan'))->assertSessionHas('error', $pesan);
        }
        // Asisten (fetch JSON) mendapat pesannya sendiri.
        $this->sebagaiIbu()->postJson(route('portal.bantuan.tanya'), ['tanya' => 'halo'])->assertForbidden()->assertJson(['message' => $pesan]);
        // Tindakan yang tetap berfungsi tidak dihadang (mis. ganti bahasa).
        $this->sebagaiIbu()->post(route('portal.bahasa'), ['bahasa' => 'en'])->assertSessionMissing('error');
    }

    public function test_kabar_hanya_berkas_dan_data_kecuali_akun_uji(): void
    {
        $kirim = fn (string $jenis) => KabarWali::keKeluarga($this->wali->refresh(), ['judul' => 'x', 'pesan' => 'y', 'jenis' => $jenis]);
        $kirim('tagihan_terbit');
        $kirim('berkas_perlu_diganti');
        $this->assertSame(['berkas_perlu_diganti'], NotifikasiWali::pluck('jenis')->unique()->values()->all());

        $this->wali->forceFill(['akun_uji' => true])->save();
        $kirim('tagihan_terbit');
        $this->assertTrue(NotifikasiWali::where('jenis', 'tagihan_terbit')->exists());
    }

    public function test_akun_uji_bebas_dan_hanya_admin_yang_menandai(): void
    {
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Staf', 'max_transaksi' => null]);
        $admin = User::create(['username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif']);
        $staf = User::create(['username' => 'stf', 'nama' => 'Staf', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif']);
        HakAksesModul::create(['id_pengguna' => $staf->id_pengguna, 'kode_modul' => 'wali', 'lihat' => true, 'buat' => true, 'ubah' => true, 'hapus' => false, 'menu' => true]);
        Akses::lupakan();

        $this->actingAs($staf)->post(route('wali.akun_uji', $this->wali->id), ['akun_uji' => 1])->assertForbidden();
        $this->actingAs($admin)->post(route('wali.akun_uji', $this->wali->id), ['akun_uji' => 1])->assertRedirect(route('wali.edit', $this->wali->id));
        $this->assertTrue($this->wali->refresh()->akun_uji);
        $this->actingAs($admin)->get(route('wali.index'))->assertSee('AKUN UJI');

        // Akun uji: tindakan tak dihadang middleware (pesan demo tak muncul).
        $this->sebagaiIbu()->from(route('portal.tagihan'))->post(route('portal.online.dompet'))
            ->assertSessionMissing('error', \App\Services\PortalWali\PortalTerbatas::pesan());
        $this->assertNotSame(\App\Services\PortalWali\PortalTerbatas::pesan(), session('error'));
    }

    public function test_saklar_mati_semua_terbuka(): void
    {
        config(['portal.terbatas' => false]);
        $this->sebagaiIbu()->from(route('portal.tagihan'))->post(route('portal.perizinan.kirim'));
        $this->assertNotSame(\App\Services\PortalWali\PortalTerbatas::pesan(), session('error'));
        $this->sebagaiIbu()->get(route('portal.beranda'))->assertDontSee('Semua menu bisa dilihat');
    }
}
