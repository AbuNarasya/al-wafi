<?php

namespace Tests\Feature;

use App\Models\HakAksesModul;
use App\Models\Level;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Beranda (4 Nov 2026): semua login mendarat di sini, termasuk pengguna tanpa hak dashboard
 * (dulu terkunci di 403). Isinya hanya menu yang boleh dibuka, Dashboard hanya bila berhak.
 */
class BerandaTest extends TestCase
{
    use RefreshDatabase;

    private function pengguna(string $username, bool $admin = false): User
    {
        Level::firstOrCreate(['kode_level' => 'L1'], ['nama_level' => 'L1', 'max_transaksi' => null]);

        return User::create(['username' => $username, 'nama' => 'Siti '.$username, 'password_hash' => Hash::make('rahasia1'),
            'kode_level' => 'L1', 'is_admin' => $admin, 'status' => 'aktif']);
    }

    private function hak(User $u, string $modul): void
    {
        HakAksesModul::create(['id_pengguna' => $u->id_pengguna, 'kode_modul' => $modul,
            'lihat' => true, 'buat' => false, 'ubah' => false, 'hapus' => false, 'menu' => true]);
    }

    public function test_login_dan_akar_mendarat_di_beranda(): void
    {
        $this->pengguna('zzbr_a');
        $this->post('/login', ['username' => 'zzbr_a', 'password' => 'rahasia1'])->assertRedirect(route('beranda'));
        $this->get('/')->assertRedirect(route('beranda'));
        $this->get(route('login'))->assertRedirect(route('beranda'));
    }

    public function test_alamat_yang_dituju_sebelum_login_tetap_dihormati(): void
    {
        $this->pengguna('zzbr_b');
        $this->get('/profil')->assertRedirect(route('login'));
        $this->post('/login', ['username' => 'zzbr_b', 'password' => 'rahasia1'])->assertRedirect('/profil');
    }

    public function test_tanpa_hak_dashboard_tetap_bisa_masuk_dan_hanya_melihat_menunya(): void
    {
        $u = $this->pengguna('zzbr_c');
        $this->hak($u, 'perizinan');

        $this->actingAs($u)->get(route('dashboard'))->assertForbidden();
        $this->actingAs($u)->get(route('beranda'))->assertOk()
            ->assertSee('Siti zzbr_c')->assertSee('Tugas Saya')->assertSee('Menu Saya')
            ->assertSee('href="'.url('/kesantrian/perizinan').'"', false)
            ->assertDontSee('Buka Dashboard')
            ->assertDontSee('href="'.url('/jurnal').'"', false);
    }

    public function test_tanpa_hak_apa_pun_tetap_bisa_masuk(): void
    {
        // Approval Inbox terbuka bagi semua pengguna — selebihnya tertutup.
        $u = $this->pengguna('zzbr_d');
        $this->actingAs($u)->get(route('beranda'))->assertOk()
            ->assertSee('href="'.url('/approvals').'"', false)
            ->assertDontSee('href="'.url('/kesantrian/perizinan').'"', false)
            ->assertDontSee('Buka Dashboard');
    }

    public function test_yang_berhak_dashboard_melihat_tombolnya(): void
    {
        $u = $this->pengguna('zzbr_e');
        $this->hak($u, 'dashboard-ppsb');
        $this->actingAs($u)->get(route('beranda'))->assertOk()->assertSee('Buka Dashboard');

        $admin = $this->pengguna('zzbr_f', true);
        $this->actingAs($admin)->get(route('beranda'))->assertOk()->assertSee('Buka Dashboard');
    }
}
