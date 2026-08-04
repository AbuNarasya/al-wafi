<?php

namespace Tests\Feature;

use App\Models\HakAksesModul;
use App\Models\Level;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * PENGGUNA BARU → MATRIKS HAK AKSES, satu tarikan alur.
 *
 * Akun yang baru dibuat tak punya satu baris pun di matriks, artinya ia belum
 * bisa membuka apa pun. Sebelum ini tak ada apa pun di layar yang mengingatkan
 * bahwa langkah kedua masih tertinggal — akun jadi "ada tapi tak bisa dipakai"
 * tanpa sebab yang terlihat.
 *
 * Yang dijaga paling keras: pengalihan ini TIDAK boleh menyeret pembuat akun
 * non-admin ke layar hak akses. Membuat akun dan membagikan kunci sengaja dua
 * wewenang berbeda; menyatukannya berarti siapa pun yang boleh membuat pengguna
 * bisa membuat akun berhak penuh lalu masuk sebagai akun itu.
 */
class HakAksesPenggunaBaruTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $tuPengguna;

    protected function setUp(): void
    {
        parent::setUp();

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);

        $this->admin = User::create(['username' => 'admin', 'nama' => 'Admin', 'password_hash' => Hash::make('x'),
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif']);

        // Staf TU: boleh mengelola akun, TIDAK boleh menyentuh matriks.
        $this->tuPengguna = User::create(['username' => 'tata', 'nama' => 'Tata Usaha', 'password_hash' => Hash::make('x'),
            'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif']);
        HakAksesModul::create(['id_pengguna' => $this->tuPengguna->id_pengguna, 'kode_modul' => 'users',
            'lihat' => true, 'buat' => true, 'ubah' => true, 'hapus' => false, 'menu' => true]);
    }

    /** @param array<string,string> $ganti */
    private function isian(array $ganti = []): array
    {
        return [
            'username' => 'baru', 'nama' => 'Pengguna Baru', 'password' => 'rahasia1',
            'kode_level' => 'L1', 'status' => 'aktif', ...$ganti,
        ];
    }

    public function test_admin_langsung_dibawa_ke_matriks_hak_akses(): void
    {
        $response = $this->actingAs($this->admin)->post(route('users.store'), $this->isian());

        $baru = User::where('username', 'baru')->firstOrFail();
        $response->assertRedirect(route('hak_akses.edit', $baru))->assertSessionHas('status');

        // Halaman tujuannya benar-benar matriks pengguna yang baru dibuat,
        // lengkap dengan peringatan bahwa ia belum bisa membuka apa pun.
        $this->actingAs($this->admin)->get(route('hak_akses.edit', $baru))
            ->assertOk()
            ->assertSee('belum pernah diatur', false);
    }

    /**
     * Pembuat akun yang bukan admin tetap berhenti di daftar pengguna — dan
     * diberi tahu bahwa hak aksesnya masih harus dimintakan.
     */
    public function test_non_admin_tidak_diseret_ke_matriks(): void
    {
        $this->actingAs($this->tuPengguna)->post(route('users.store'), $this->isian())
            ->assertRedirect(route('users.index'));

        $pesan = session('status');
        $this->assertStringContainsString('administrator', $pesan);

        // Dan memang tak boleh membukanya sendiri.
        $baru = User::where('username', 'baru')->firstOrFail();
        $this->actingAs($this->tuPengguna)->get(route('hak_akses.edit', $baru))->assertForbidden();
    }

    /** Pengguna yang haknya sudah pernah diatur tak diberi pesan "belum pernah diatur". */
    public function test_pesan_belum_diatur_hanya_untuk_yang_masih_kosong(): void
    {
        $this->actingAs($this->admin)->get(route('hak_akses.edit', $this->tuPengguna))
            ->assertOk()
            ->assertDontSee('belum pernah diatur', false);
    }

    /** Menyunting pengguna lama tak ikut dialihkan — hanya pembuatan yang butuh langkah kedua. */
    public function test_menyunting_pengguna_lama_tetap_kembali_ke_daftar(): void
    {
        $this->actingAs($this->admin)->put(route('users.update', $this->tuPengguna), $this->isian([
            'username' => 'tata', 'nama' => 'Tata Usaha Baru', 'password' => '',
        ]))->assertRedirect(route('users.index'));
    }
}
