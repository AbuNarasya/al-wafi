<?php

namespace Tests\Feature;

use App\Models\Level;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * PROFIL SAYA — penggantian kata sandi oleh pemilik akun sendiri.
 *
 * Yang dijaga di sini terutama dua hal yang berbahaya bila salah: (1) halaman
 * ini tak boleh menyentuh akun siapa pun selain yang sedang masuk, dan (2)
 * kata sandi lama WAJIB dibuktikan — tanpa itu, komputer yang ditinggal
 * terbuka sebentar cukup untuk membajak akun secara permanen.
 */
class UbahKataSandiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Level::create(['kode_level' => 'L1', 'nama_level' => 'Staff', 'max_transaksi' => null]);

        $this->user = User::create([
            'username' => 'siti', 'nama' => 'Siti', 'password_hash' => Hash::make('lama123'),
            'kode_level' => 'L1', 'is_admin' => false, 'tim_keuangan' => false, 'status' => 'aktif',
        ]);
    }

    /** @param array<string,string> $isian */
    private function ganti(array $isian): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->put(route('profil.kata_sandi'), $isian);
    }

    /**
     * Pengguna biasa tanpa hak akses modul apa pun tetap harus bisa membuka
     * profilnya — mengganti sandi sendiri bukan kewenangan modul.
     */
    public function test_pengguna_biasa_bisa_membuka_halaman_profil(): void
    {
        $this->actingAs($this->user)->get(route('profil.index'))
            ->assertOk()
            ->assertSee('Ganti Kata Sandi')
            ->assertSee('siti');
    }

    public function test_tamu_tidak_bisa_membuka_profil(): void
    {
        $this->get(route('profil.index'))->assertRedirect(route('login'));
    }

    public function test_kata_sandi_berhasil_diganti_dan_bisa_dipakai_masuk(): void
    {
        $this->ganti([
            'password_lama' => 'lama123',
            'password_baru' => 'baru456',
            'password_baru_confirmation' => 'baru456',
        ])->assertRedirect(route('profil.index'))->assertSessionHas('status');

        $this->assertTrue(Hash::check('baru456', $this->user->fresh()->password_hash));

        // Bukti yang sebenarnya: pintu masuk menerima sandi baru, menolak lama.
        $this->post('/login', ['username' => 'siti', 'password' => 'baru456'])
            ->assertRedirect(route('dashboard'));

        $this->post('/login', ['username' => 'siti', 'password' => 'lama123'])
            ->assertSessionHasErrors('username');
    }

    public function test_kata_sandi_lama_salah_ditolak(): void
    {
        $this->ganti([
            'password_lama' => 'bukan-ini',
            'password_baru' => 'baru456',
            'password_baru_confirmation' => 'baru456',
        ])->assertSessionHasErrors('password_lama');

        $this->assertTrue(Hash::check('lama123', $this->user->fresh()->password_hash));
    }

    public function test_konfirmasi_tidak_sama_ditolak(): void
    {
        $this->ganti([
            'password_lama' => 'lama123',
            'password_baru' => 'baru456',
            'password_baru_confirmation' => 'baru457',
        ])->assertSessionHasErrors('password_baru');

        $this->assertTrue(Hash::check('lama123', $this->user->fresh()->password_hash));
    }

    public function test_kata_sandi_baru_terlalu_pendek_atau_sama_dengan_lama_ditolak(): void
    {
        $this->ganti([
            'password_lama' => 'lama123',
            'password_baru' => 'abc',
            'password_baru_confirmation' => 'abc',
        ])->assertSessionHasErrors('password_baru');

        $this->ganti([
            'password_lama' => 'lama123',
            'password_baru' => 'lama123',
            'password_baru_confirmation' => 'lama123',
        ])->assertSessionHasErrors('password_baru');

        $this->assertTrue(Hash::check('lama123', $this->user->fresh()->password_hash));
    }

    /**
     * Akun lain tak boleh ikut terganti — sasarannya selalu pengguna yang
     * sedang masuk, tak pernah id dari permintaan.
     */
    public function test_akun_lain_tidak_ikut_berubah(): void
    {
        $lain = User::create([
            'username' => 'umar', 'nama' => 'Umar', 'password_hash' => Hash::make('rahasia9'),
            'kode_level' => 'L1', 'is_admin' => true, 'tim_keuangan' => false, 'status' => 'aktif',
        ]);

        $this->ganti([
            'id_pengguna' => $lain->id_pengguna,
            'password_lama' => 'lama123',
            'password_baru' => 'baru456',
            'password_baru_confirmation' => 'baru456',
        ])->assertRedirect(route('profil.index'));

        $this->assertTrue(Hash::check('rahasia9', $lain->fresh()->password_hash));
    }

    /**
     * Tombol mata di halaman login: tanpa ini, salah ketik yang tak terlihat
     * jadi sebab tersering pengguna mengira akunnya terkunci.
     */
    public function test_halaman_login_punya_tombol_tampilkan_kata_sandi(): void
    {
        $this->get('/login')->assertOk()->assertSee('Tampilkan kata sandi');
    }
}
