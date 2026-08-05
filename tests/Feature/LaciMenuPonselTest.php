<?php

namespace Tests\Feature;

use App\Models\Level;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Menu samping menjadi LACI di layar sempit, dan TETAP seperti dulu di ≥1024px.
 *
 * Rupanya tak bisa diuji di sini — PHPUnit tak mengenal lebar layar. Yang dijaga
 * test ini adalah hal yang tetap bisa dipercaya: markup penopangnya masih terkirim.
 * Tiga hal itu pernah hilang tanpa disadari di proyek lain karena dianggap
 * "cuma kelas CSS":
 *
 *   • nilai awal menu dibaca dari LEBAR LAYAR, bukan dipaku `true` — inilah yang
 *     membuat menu tak lagi menutupi dua pertiga layar ponsel di setiap halaman;
 *   • tirai laci ada DAN disembunyikan di ≥lg, sehingga desktop tak bergantung
 *     pada JavaScript untuk tetap normal;
 *   • isi halaman tetap digeser hanya di ≥lg (`lg:pl-64`).
 */
class LaciMenuPonselTest extends TestCase
{
    use RefreshDatabase;

    private User $pengguna;

    protected function setUp(): void
    {
        parent::setUp();
        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->pengguna = User::create([
            'username' => 'zzlaci', 'nama' => 'Admin Laci', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif',
        ]);
    }

    public function test_menu_samping_tidak_lagi_selalu_terbuka(): void
    {
        $halaman = $this->actingAs($this->pengguna)->get(route('dashboard'))->assertOk();

        $halaman->assertSee("sidebar: window.matchMedia('(min-width: 1024px)').matches", false);
        // Nilai lama yang membuat menu menutupi layar ponsel di tiap halaman.
        $halaman->assertDontSee('x-data="{ sidebar: true }"', false);
    }

    public function test_tirai_laci_ada_dan_hanya_di_layar_sempit(): void
    {
        $this->actingAs($this->pengguna)->get(route('dashboard'))->assertOk()
            ->assertSee('class="fixed inset-0 z-30 bg-slate-900/50 lg:hidden"', false)
            // Tirai harus DI BAWAH laci, kalau tidak lacinya ikut tergelapkan.
            ->assertSee('fixed inset-y-0 left-0 z-40 w-64', false);
    }

    public function test_isi_halaman_hanya_digeser_di_layar_lebar(): void
    {
        $this->actingAs($this->pengguna)->get(route('dashboard'))->assertOk()
            ->assertSee("sidebar ? 'lg:pl-64' : ''", false);
    }

    /** Menutup laci saat menu dipilih hanya berlaku di layar sempit. */
    public function test_laci_menutup_saat_menu_dipilih_kecuali_di_desktop(): void
    {
        $this->actingAs($this->pengguna)->get(route('dashboard'))->assertOk()
            ->assertSee("if (! lebar && \$event.target.closest('a')) sidebar = false", false)
            ->assertSee('@keydown.escape.window="if (! lebar) sidebar = false"', false);
    }
}
