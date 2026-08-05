<?php

namespace Tests\Feature;

use App\Models\Level;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kartu angka headline tidak boleh dipaksa dua kolom di layar ponsel.
 *
 * Ditemukan pengguna setelah sisiran Fase 05 lolos: nominal "Rp 1.128.000.000"
 * selebar 195px dijejalkan ke kartu 164px, dan 64px-nya terpotong tepi kartu.
 *
 * Sisiran itu tak menangkapnya karena dua sebab yang layak diingat: teksnya
 * terpotong DI DALAM kartu sehingga halaman tak ikut menggeser, dan database
 * uji yang dirender hanya berisi angka kecil. Pengukuran lebar membuktikan
 * layar rapi; ia tak membuktikan angka terbesar pun muat.
 *
 * `.rp` sengaja `white-space: nowrap` supaya "Rp" tak pernah terpisah dari
 * angkanya — jadi nominal tak bisa membungkus untuk menyelamatkan diri, dan
 * satu-satunya jalan adalah memberi kartunya lebar penuh di ponsel.
 */
class KartuAngkaPonselTest extends TestCase
{
    use RefreshDatabase;

    public function test_kartu_headline_dashboard_satu_kolom_di_ponsel(): void
    {
        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $admin = User::create([
            'username' => 'zzkartu', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif',
        ]);

        $markup = preg_replace('/\s+/', ' ', $this->actingAs($admin)->get(route('dashboard'))->assertOk()->content());

        $this->assertStringContainsString('grid gap-4 sm:grid-cols-2 lg:grid-cols-4', $markup);
        // Bentuk lama: dua kolom sejak layar terkecil.
        $this->assertStringNotContainsString('grid grid-cols-2 gap-4 lg:grid-cols-4', $markup);
    }
}
