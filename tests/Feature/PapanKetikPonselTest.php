<?php

namespace Tests\Feature;

use App\Models\Level;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PAPAN KETIK PONSEL untuk isian yang berisi angka tetapi disimpan sebagai teks.
 *
 * Nomor telepon, NIS, NISN, dan NIK tak bisa memakai `type="number"` — nol di
 * depan dan tanda "+" akan hilang. Akibatnya ponsel memunculkan papan ketik
 * HURUF, dan petugas menekan "?123" dulu setiap kali. `inputmode` di komponen
 * <x-field> menyelesaikannya di satu tempat untuk seluruh formulir.
 *
 * Diuji lewat halaman sungguhan, bukan komponen yang dirender terpisah: yang
 * ingin dijaga adalah atributnya benar-benar sampai ke layar.
 */
class PapanKetikPonselTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->admin = User::create([
            'username' => 'zzketik', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif',
        ]);
    }

    /** Markup dirapatkan dulu: Blade menyisipkan baris baru antar atribut. */
    private function markup(string $rute): string
    {
        return preg_replace('/\s+/', ' ', $this->actingAs($this->admin)->get(route($rute))->assertOk()->content());
    }

    public function test_isian_telepon_memakai_papan_ketik_telepon(): void
    {
        $this->assertStringContainsString(
            'name="telepon" type="text" value="" inputmode="tel"',
            $this->markup('vendors.create'),
        );
    }

    public function test_isian_biasa_tidak_dipaksa_jadi_angka(): void
    {
        // Nama vendor jelas huruf — memberinya papan ketik angka justru
        // menyulitkan, jadi isian yang tak dikenali dibiarkan apa adanya.
        $this->assertStringContainsString(
            'name="nama_vendor" type="text" value="" class=',
            $this->markup('vendors.create'),
        );
    }
}
