<?php

namespace Tests\Feature;

use App\Models\Level;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PWA — aplikasi bisa dipasang ke layar utama & terbuka tanpa bilah alamat.
 *
 * Yang dijaga di sini bagian yang bisa hilang tanpa terlihat: penanda di
 * <head>, dan yang paling penting SAKLAR MATI-nya. Pekerja layanan adalah satu-
 * satunya bagian pekerjaan ponsel ini yang mengenai SEMUA peramban, termasuk
 * Chrome desktop staf — jadi kemampuan mematikannya dari server harus tetap
 * ada, dan tak boleh diam-diam lenyap saat berkas layout disunting kelak.
 */
class PwaTest extends TestCase
{
    use RefreshDatabase;

    private User $pengguna;

    protected function setUp(): void
    {
        parent::setUp();
        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->pengguna = User::create([
            'username' => 'zzpwa', 'nama' => 'Admin PWA', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif',
        ]);
    }

    public function test_penanda_pwa_ada_di_setiap_halaman(): void
    {
        $this->actingAs($this->pengguna)->get(route('dashboard'))->assertOk()
            ->assertSee('<link rel="manifest" href="/manifest.json">', false)
            ->assertSee('<meta name="theme-color" content="#164a9e">', false)
            ->assertSee('<link rel="apple-touch-icon" href="/ikon/apple-touch-icon.png">', false)
            ->assertSee('<meta name="apple-mobile-web-app-title" content="Al Wafi ERP">', false);
    }

    public function test_saklar_mati_dari_server(): void
    {
        config()->set('app.pwa_aktif', true);
        $this->actingAs($this->pengguna)->get(route('dashboard'))->assertOk()
            ->assertSee('<meta name="pwa" content="aktif">', false);

        // PWA_AKTIF=false → halaman menyuruh peramban MENCABUT pekerja layanan
        // yang sudah terpasang, bukan sekadar berhenti mendaftarkannya.
        config()->set('app.pwa_aktif', false);
        $this->actingAs($this->pengguna)->get(route('dashboard'))->assertOk()
            ->assertSee('<meta name="pwa" content="mati">', false);
    }

    public function test_berkas_pendukung_pwa_ada(): void
    {
        $publik = public_path();

        foreach (['manifest.json', 'sw.js', 'luring.html', 'ikon/ikon-192.png', 'ikon/ikon-512.png', 'ikon/apple-touch-icon.png'] as $berkas) {
            $this->assertFileExists("{$publik}/{$berkas}");
        }

        $manifest = json_decode(file_get_contents("{$publik}/manifest.json"), true);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('/dashboard', $manifest['start_url']);
        // Ikon `maskable` wajib: tanpa itu Android memberi ikon kita bingkai
        // putih sendiri, dan lambangnya jadi kotak kecil di tengah tempelan.
        $this->assertSame(['any maskable', 'any maskable'], array_column($manifest['icons'], 'purpose'));
    }

    /**
     * Pekerja layanan TIDAK BOLEH menyimpan halaman ber-sesi: halaman tersimpan
     * bisa menampilkan data milik pengguna sebelumnya, atau angka keuangan basi
     * yang tampak mutakhir.
     */
    public function test_pekerja_layanan_hanya_menyimpan_aset_statis(): void
    {
        $sw = file_get_contents(public_path('sw.js'));

        $this->assertStringContainsString("req.method !== 'GET'", $sw);
        $this->assertStringContainsString("url.pathname.startsWith('/build/')", $sw);
        $this->assertStringContainsString("req.mode === 'navigate'", $sw);
        // Tak ada penyimpanan menyeluruh atas seluruh permintaan.
        $this->assertStringNotContainsString('caches.put(req)', str_replace(' ', '', $sw));
    }
}
