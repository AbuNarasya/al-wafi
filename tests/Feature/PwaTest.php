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

    /** @return list<string> */
    private function penandaPwa(): array
    {
        return [
            '<meta name="pwa" content="aktif">',
            '<link rel="manifest" href="/manifest.json">',
            '<meta name="theme-color" content="#164a9e">',
            '<link rel="apple-touch-icon" href="/ikon/apple-touch-icon.png?v=6">',
            '<meta name="apple-mobile-web-app-title" content="Al Wafi ERP">',
        ];
    }

    public function test_penanda_pwa_ada_di_halaman_ber_sesi(): void
    {
        config()->set('app.pwa_aktif', true);
        $halaman = $this->actingAs($this->pengguna)->get(route('dashboard'))->assertOk();

        foreach ($this->penandaPwa() as $penanda) {
            $halaman->assertSee($penanda, false);
        }
    }

    /**
     * HALAMAN MASUK JUGA — dan taruhannya lebih besar daripada sekadar tombol
     * "Pasang" yang tak muncul.
     *
     * `meta[name="pwa"]` yang TIDAK ADA terbaca app.js sebagai "mati", dan
     * cabang itu MENCABUT pendaftaran pekerja layanan. Selama penanda ini cuma
     * hidup di layout ber-sesi, setiap kali staf keluar akun atau sesinya
     * kedaluwarsa, pemasangan di layar utamanya dibongkar sendiri oleh halaman
     * masuk — tanpa ada yang menyadarinya.
     */
    public function test_penanda_pwa_ada_di_halaman_masuk(): void
    {
        config()->set('app.pwa_aktif', true);
        $halaman = $this->get(route('login'))->assertOk();

        foreach ($this->penandaPwa() as $penanda) {
            $halaman->assertSee($penanda, false);
        }
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

        foreach (['manifest.json', 'sw.js', 'luring.html', 'ikon/ikon-192.png', 'ikon/ikon-512.png', 'ikon/apple-touch-icon.png',
            'ikon/ikon-any-192.png', 'ikon/ikon-any-512.png'] as $berkas) {
            $this->assertFileExists("{$publik}/{$berkas}");
        }

        $manifest = json_decode(file_get_contents("{$publik}/manifest.json"), true);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('/dashboard', $manifest['start_url']);
        $this->assertSusunanIkon($manifest);
    }

    /**
     * Logo LENGKAP dengan tulisan "AL WAFI ERP" (permintaan user 15 Okt 2026), dua jenis:
     *  • `any` (ikon-any-*) — logo utuh, untuk layar pembuka;
     *  • `maskable` (ikon-*) WAJIB ada: tanpa itu Android memberi ikon kita bingkai putih
     *    sendiri. Lambang+tulisannya diletakkan di dalam lingkaran aman 80% supaya tak terpotong.
     * Tak lagi "any maskable" dalam satu berkas: logo bertulisan tak muat di zona aman bila utuh.
     *
     * `?v=` WAJIB: server mengirim /ikon/ dengan max-age 7 hari, dan nama berkas yang sama
     * membuat HP yang memasang ulang tetap memakai gambar lama dari cache-nya (terjadi 15 Okt).
     */
    private function assertSusunanIkon(array $manifest): void
    {
        $this->assertSame(
            ['/ikon/ikon-any-192.png?v=6 any', '/ikon/ikon-any-512.png?v=6 any', '/ikon/ikon-192.png?v=6 maskable', '/ikon/ikon-512.png?v=6 maskable'],
            array_map(fn ($i) => $i['src'].' '.$i['purpose'], $manifest['icons']));
    }

    /**
     * Portal Wali = aplikasi TERPISAH "Al Wafi Wali" (13 Okt 2026): manifest sendiri yang
     * membuka beranda wali, sementara halaman staf tetap memakai manifest staf.
     */
    public function test_portal_wali_punya_manifest_sendiri(): void
    {
        $m = json_decode(file_get_contents(public_path('manifest-wali.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['/wali/', '/wali/beranda', '/wali/', 'standalone', 'Al Wafi Wali'],
            [$m['id'], $m['start_url'], $m['scope'], $m['display'], $m['name']]);
        $this->assertSusunanIkon($m);

        $this->get(route('portal.masuk'))->assertOk()
            ->assertSee('<link rel="manifest" href="/manifest-wali.json">', false)
            ->assertSee('<meta name="apple-mobile-web-app-title" content="Al Wafi Wali">', false)
            ->assertSee('<meta name="pwa" content="aktif">', false);
        $this->get(route('login'))->assertOk()->assertSee('<link rel="manifest" href="/manifest.json">', false);
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

    /**
     * IKON TIDAK BOLEH ikut aturan "simpan selamanya" milik aset build.
     *
     * Nama berkas build mengandung hash, jadi isinya memang tak pernah berubah.
     * Nama ikon TETAP (`/ikon/ikon-192.png`) walau gambarnya diganti — dan
     * keduanya pernah disatukan dalam satu cabang cache-first. Akibatnya ponsel
     * yang sudah menyimpannya menampilkan lambang lama di dialog "tambahkan ke
     * layar utama" tanpa batas waktu, meski servernya sudah menyajikan yang
     * baru. Yang dijaga di sini: ikon punya cabangnya sendiri, dan cabang itu
     * selalu mengunduh ulang lalu menimpa simpanannya.
     */
    public function test_ikon_tidak_disimpan_selamanya(): void
    {
        $sw = file_get_contents(public_path('sw.js'));
        $rapat = str_replace(' ', '', $sw);

        $this->assertStringContainsString("url.pathname.startsWith('/ikon/')", $sw);

        // Bukan lagi satu cabang bersama aset build.
        $this->assertStringNotContainsString(
            "startsWith('/build/')||url.pathname.startsWith('/ikon/')",
            $rapat,
        );

        // Cabang ikon menimpa simpanannya dengan hasil unduhan baru.
        $this->assertMatchesRegularExpression("#startsWith\('/ikon/'\).*?c\.put\(req,res\.clone\(\)\)#s", $rapat);
    }
}
