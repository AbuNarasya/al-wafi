<?php

namespace Tests\Feature;

use App\Console\Commands\ImporArtikelBantuan;
use App\Models\HakAksesModul;
use App\Models\Level;
use App\Models\User;
use App\Models\Wali;
use App\Services\PortalWali\AsistenWali;
use App\Services\PortalWali\MenuPortal;
use App\Support\Akses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as KirimanHttp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ASISTEN BANTUAN PORTAL WALI (11 Nov 2026): jawaban hanya dari artikel, AI gratis Groq/Gemini, jatuh ke
 * pencarian artikel bila AI tak bisa dipakai, samaran angka/email, batas harian, saklar & hak staf.
 */
class AsistenWaliTest extends TestCase
{
    use RefreshDatabase;

    private Wali $wali;

    protected function setUp(): void
    {
        parent::setUp();
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Staf', 'max_transaksi' => null]);
        $this->wali = Wali::create(['kontak_utama' => 'ibu', 'nama_ibu' => 'Ibu Aminah', 'telepon_ibu' => '0811', 'email_ibu' => 'aminah@contoh.id',
            'nama' => 'Ibu Aminah', 'telepon' => '0811', 'status' => 'aktif']);
        DB::table('artikel_bantuan')->insert([
            ['judul' => 'Bagaimana cara membayar tagihan SPP?', 'isi' => '1. Buka Menu Lengkap › Keuangan › Tagihan. 2. Pilih tagihan lalu Bayar.',
                'kata_kunci' => 'bayar, spp, tagihan, transfer', 'urutan' => 10, 'aktif' => true, 'created_at' => now(), 'updated_at' => now()],
            ['judul' => 'Bagaimana cara mengajukan izin pulang?', 'isi' => 'Buka Menu Lengkap › Administrasi › Perizinan Insidental lalu Ajukan.',
                'kata_kunci' => 'izin, pulang, perizinan', 'urutan' => 20, 'aktif' => true, 'created_at' => now(), 'updated_at' => now()],
            ['judul' => 'Artikel lama yang disembunyikan', 'isi' => 'tagihan lama', 'kata_kunci' => 'tagihan',
                'urutan' => 30, 'aktif' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);
        config(['services.asisten_wali.groq_key' => 'kunci-uji-groq', 'services.asisten_wali.gemini_key' => 'kunci-uji-gemini']);
    }

    private function atur(array $nilai): void
    {
        DB::table('pengaturan_asisten_wali')->update($nilai);
    }

    private function sebagaiWali(): static
    {
        return $this->actingAs($this->wali, 'wali')->withSession(['portal.peran' => 'ibu', 'portal.punya_sandi' => true]);
    }

    private function pengguna(array $aksi): User
    {
        $user = User::create(['username' => 'staf', 'nama' => 'Staf', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif'])->fresh();
        HakAksesModul::create(['id_pengguna' => $user->id_pengguna, 'kode_modul' => 'asisten-wali', 'lihat' => true,
            'buat' => in_array('buat', $aksi, true), 'ubah' => in_array('ubah', $aksi, true), 'hapus' => in_array('hapus', $aksi, true), 'menu' => true]);
        Akses::lupakan();

        return $user;
    }

    public function test_samaran_dan_pencarian_artikel(): void
    {
        $this->assertSame('NIS [angka], hp [angka], surel [email]', AsistenWali::samarkan('NIS 2024001234, hp 0812-3456-7890, surel ayah.budi@gmail.com'));
        $this->assertSame('kelas 7 bayar 500 ribu', AsistenWali::samarkan('kelas 7 bayar 500 ribu'), 'angka pendek tetap utuh');

        $hasil = AsistenWali::cariArtikel('gimana cara bayar spp anak saya?');
        $this->assertSame('Bagaimana cara membayar tagihan SPP?', $hasil->first()->judul);
        $this->assertCount(1, $hasil, 'artikel yang tak cocok & artikel nonaktif tak ikut');
        $this->assertTrue(AsistenWali::cariArtikel('tagihannya')->contains('judul', 'Bagaimana cara membayar tagihan SPP?'), 'akhiran -nya dikenali');
        $this->assertCount(0, AsistenWali::cariArtikel('apa itu?'));
        $this->assertCount(0, AsistenWali::cariArtikel('nis'), 'kata pendek tak cocok di tengah kata ("administrasi")');
    }

    public function test_menu_dan_halaman_hanya_ada_saat_dinyalakan(): void
    {
        $this->assertArrayNotHasKey('bantuan', MenuPortal::tersedia($this->wali));
        $this->sebagaiWali()->get('/wali/bantuan')->assertNotFound();
        $this->sebagaiWali()->postJson('/wali/bantuan/tanya', ['pertanyaan' => 'bayar spp'])->assertNotFound();

        $this->sebagaiWali()->get('/wali/kabar')->assertOk()->assertDontSee('aria-label="Bantuan"', false);
        $this->atur(['aktif' => true]);
        $this->assertArrayHasKey('bantuan', MenuPortal::tersedia($this->wali));
        // Tombol Bantuan melayang di setiap halaman portal (kecuali halaman Bantuan itu sendiri).
        $this->sebagaiWali()->get('/wali/kabar')->assertOk()->assertSee('aria-label="Bantuan"', false);
        $this->sebagaiWali()->get('/wali/bantuan')->assertOk()
            ->assertSee('Asisten Bantuan')->assertSee('Bagaimana cara membayar tagihan SPP?')
            ->assertDontSee('Artikel lama yang disembunyikan');

        // Mode verifikasi (link WA) hanya membuka /wali/data & /wali/verifikasi.
        $this->actingAs($this->wali, 'wali')->withSession(['portal.peran' => 'ibu', 'portal.verifikasi' => 1])
            ->get('/wali/bantuan')->assertRedirect();
    }

    public function test_jawaban_groq_dengan_artikel_dan_samaran(): void
    {
        $this->atur(['aktif' => true, 'penyedia' => 'groq']);
        Http::fake(['api.groq.com/*' => Http::response(['choices' => [['message' => ['content' => "**Caranya:**\n1. Buka Tagihan."]]]])]);

        $r = $this->sebagaiWali()->postJson('/wali/bantuan/tanya', ['pertanyaan' => 'Cara bayar spp anak NIS 2024001234?'])->assertOk();
        $r->assertJsonPath('sumber', 'groq')->assertJsonPath('jawaban', "Caranya:\n1. Buka Tagihan.")
            ->assertJsonPath('artikel.0.judul', 'Bagaimana cara membayar tagihan SPP?')->assertJsonPath('sisa', 19);

        Http::assertSent(function (KirimanHttp $k) {
            $isi = $k->data();
            $sistem = $isi['messages'][0]['content'];

            return $k->url() === 'https://api.groq.com/openai/v1/chat/completions'
                && $k->hasHeader('Authorization', 'Bearer kunci-uji-groq')
                && $isi['model'] === 'openai/gpt-oss-120b'
                && $isi['reasoning_effort'] === 'low'
                && str_contains($sistem, 'Menu Lengkap › Keuangan › Tagihan')            // artikel cocok dikirim utuh
                && str_contains($sistem, '- Bagaimana cara mengajukan izin pulang?')     // artikel lain hanya judul
                && ! str_contains($sistem, 'Artikel lama yang disembunyikan')
                && end($isi['messages'])['content'] === 'Cara bayar spp anak NIS [angka]?'; // angka tak keluar
        });

        $log = DB::table('percakapan_asisten')->first();
        $this->assertSame('Cara bayar spp anak NIS [angka]?', $log->pertanyaan);
        $this->assertSame('groq', $log->sumber);
        $this->assertNull($log->galat);
    }

    public function test_gemini_dan_riwayat_percakapan_ikut_dikirim(): void
    {
        $this->atur(['aktif' => true, 'penyedia' => 'gemini']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(['candidates' => [['content' => ['parts' => [['text' => 'Buka Perizinan Insidental.']]]]]])
            ->push(['candidates' => [['content' => ['parts' => [['text' => 'Lalu tekan Ajukan.']]]]]])]);

        $this->sebagaiWali()->postJson('/wali/bantuan/tanya', ['pertanyaan' => 'cara izin pulang'])->assertJsonPath('sumber', 'gemini');
        $this->sebagaiWali()->postJson('/wali/bantuan/tanya', ['pertanyaan' => 'lalu?'])->assertJsonPath('jawaban', 'Lalu tekan Ajukan.');

        Http::assertSent(fn (KirimanHttp $k) => str_contains($k->url(), 'models/gemini-3.5-flash-lite:generateContent')
            && $k->hasHeader('x-goog-api-key', 'kunci-uji-gemini')
            && count($k->data()['contents']) === 3
            && $k->data()['contents'][1] === ['role' => 'model', 'parts' => [['text' => 'Buka Perizinan Insidental.']]]);
    }

    public function test_groq_penuh_dialihkan_ke_gemini_lalu_artikel(): void
    {
        $this->atur(['aktif' => true, 'penyedia' => 'groq']);
        Http::fake([
            'api.groq.com/*' => Http::response(['error' => ['message' => 'Rate limit reached ... tokens per minute']], 429),
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'Jawaban dari Gemini.']]]]]]),
        ]);
        $this->sebagaiWali()->postJson('/wali/bantuan/tanya', ['pertanyaan' => 'bayar spp'])->assertOk()
            ->assertJsonPath('sumber', 'gemini')->assertJsonPath('jawaban', 'Jawaban dari Gemini.');
        $log = DB::table('percakapan_asisten')->latest('id')->first();
        $this->assertSame('gemini', $log->sumber);
        $this->assertStringContainsString('Groq: kuota/batas paket gratis tercapai', $log->galat, 'galat penyedia utama tetap dicatat');

        // Cadangan dimatikan → langsung ke pencarian artikel, Gemini tak disentuh.
        $this->atur(['cadangan' => false]);
        Http::fake(['api.groq.com/*' => Http::response(['error' => ['message' => 'Rate limit']], 429), 'generativelanguage.googleapis.com/*' => Http::response([], 500)]);
        $this->sebagaiWali()->postJson('/wali/bantuan/tanya', ['pertanyaan' => 'bayar spp'])->assertJsonPath('sumber', 'artikel');
        Http::assertNotSent(fn (KirimanHttp $k) => str_contains($k->url(), 'generativelanguage'));
    }

    public function test_ai_gagal_jatuh_ke_artikel(): void
    {
        $this->atur(['aktif' => true, 'penyedia' => 'groq']);
        config(['services.asisten_wali.gemini_key' => '']); // tanpa cadangan
        Http::fake(['api.groq.com/*' => Http::response(['error' => ['message' => 'Rate limit reached']], 429)]);

        $this->sebagaiWali()->postJson('/wali/bantuan/tanya', ['pertanyaan' => 'bayar spp'])->assertOk()
            ->assertJsonPath('sumber', 'artikel')->assertJsonPath('artikel.0.judul', 'Bagaimana cara membayar tagihan SPP?');
        $this->assertStringContainsString('kuota/batas paket gratis tercapai', DB::table('percakapan_asisten')->value('galat'));

        // Tanpa kunci: tak ada kiriman ke luar sama sekali.
        config(['services.asisten_wali.groq_key' => '']);
        Http::fake();
        $this->sebagaiWali()->postJson('/wali/bantuan/tanya', ['pertanyaan' => 'apa kabar dunia'])->assertOk()
            ->assertJsonPath('sumber', 'artikel')->assertJsonPath('artikel', []);
        Http::assertNothingSent();
    }

    public function test_batas_harian_per_keluarga(): void
    {
        $this->atur(['aktif' => true, 'batas_harian' => 2]);
        config(['services.asisten_wali.groq_key' => '']);
        $this->sebagaiWali()->postJson('/wali/bantuan/tanya', ['pertanyaan' => 'bayar spp'])->assertJsonPath('sisa', 1);
        $this->sebagaiWali()->postJson('/wali/bantuan/tanya', ['pertanyaan' => 'izin pulang'])->assertJsonPath('sisa', 0);
        $this->sebagaiWali()->postJson('/wali/bantuan/tanya', ['pertanyaan' => 'lagi'])->assertStatus(429)
            ->assertJsonPath('message', 'Batas pertanyaan hari ini sudah tercapai. Silakan coba lagi besok, atau hubungi admin pesantren.');
        $this->assertSame(2, DB::table('percakapan_asisten')->count());

        // Kemarin tak dihitung.
        DB::table('percakapan_asisten')->update(['created_at' => now()->subDay()]);
        $this->assertSame(2, AsistenWali::sisaHariIni($this->wali));
    }

    public function test_layar_staf_hak_dan_uji_banding(): void
    {
        $lihat = $this->pengguna([]);
        $this->actingAs($lihat)->get('/pengaturan/asisten-wali')->assertOk()->assertSee('Uji banding Groq vs Gemini')->assertDontSee('>Simpan<', false);
        $this->actingAs($lihat)->put('/pengaturan/asisten-wali', ['aktif' => 1, 'penyedia' => 'gemini', 'model_groq' => 'x', 'model_gemini' => 'y', 'batas_harian' => 5])->assertForbidden();

        Http::fake([
            'api.groq.com/*' => Http::response(['choices' => [['message' => ['content' => 'Jawaban Groq']]]]),
            'generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'API key not valid']], 400),
        ]);
        $this->actingAs($lihat)->post('/pengaturan/asisten-wali/uji', ['pertanyaan' => 'bayar spp'])->assertRedirect();
        $this->actingAs($lihat)->get('/pengaturan/asisten-wali')->assertSee('Jawaban Groq')->assertSee('API key not valid');
        $this->assertSame(0, DB::table('percakapan_asisten')->count(), 'uji banding tak dicatat');

        DB::table('hak_akses_modul')->where('id_pengguna', $lihat->id_pengguna)->update(['ubah' => true, 'buat' => true, 'hapus' => true]);
        Akses::lupakan();
        $this->actingAs($lihat)->put('/pengaturan/asisten-wali', ['aktif' => 1, 'penyedia' => 'gemini', 'model_groq' => 'llama-3.1-8b-instant', 'model_gemini' => 'gemini-2.5-flash', 'batas_harian' => 5, 'batas_harian_staf' => 40, 'aktif_staf' => 1])
            ->assertRedirect('/pengaturan/asisten-wali');
        $atur = AsistenWali::pengaturan();
        $this->assertTrue((bool) $atur->aktif);
        $this->assertSame('gemini', $atur->penyedia);
        $this->assertSame(5, (int) $atur->batas_harian);
        $this->assertSame(40, (int) $atur->batas_harian_staf);
        $this->assertTrue((bool) $atur->aktif_staf);
        $this->assertDatabaseHas('activity_log', ['aksi' => 'ubah_asisten_wali']);

        $this->actingAs($lihat)->post('/pengaturan/asisten-wali/artikel', ['judul' => 'Cara pasang aplikasi?', 'isi' => 'Ketuk Pasang.', 'kata_kunci' => 'pasang, hp', 'aktif' => 1])->assertRedirect();
        $this->actingAs($lihat)->post('/pengaturan/asisten-wali/artikel', ['judul' => 'Cara pasang aplikasi?', 'isi' => 'Dobel.'])->assertSessionHasErrors('judul');
        $id = DB::table('artikel_bantuan')->where('judul', 'Cara pasang aplikasi?')->value('id');
        $this->actingAs($lihat)->delete("/pengaturan/asisten-wali/artikel/{$id}")->assertRedirect();
        $this->assertDatabaseMissing('artikel_bantuan', ['id' => $id]);
    }

    public function test_asisten_staf_untuk_semua_yang_login_dan_artikel_terpisah_dari_wali(): void
    {
        $staf = User::create(['username' => 'kasir', 'nama' => 'Kasir Satu', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif'])->fresh();
        DB::table('artikel_bantuan')->insert(['audiens' => 'staf', 'judul' => 'Bagaimana cara menerbitkan SPP bulanan?',
            'isi' => 'Buka KEPENDIDIKAN › Administrasi › SPP, pilih periode, lalu Terbitkan.', 'kata_kunci' => 'spp, terbitkan, tagihan',
            'urutan' => 10, 'aktif' => true, 'created_at' => now(), 'updated_at' => now()]);

        // Mati: tombol tak ada, alamat 404.
        $this->actingAs($staf)->get('/beranda')->assertDontSee('bantuanStaf(', false);
        $this->actingAs($staf)->postJson('/bantuan/tanya', ['pertanyaan' => 'spp'])->assertNotFound();

        $this->atur(['aktif_staf' => true, 'batas_harian_staf' => 2]);
        $this->actingAs($staf)->get('/beranda')->assertSee('bantuanStaf(', false);
        $this->assertFalse(AsistenWali::aktif(), 'saklar staf tak menyalakan portal wali');

        Http::fake(['api.groq.com/*' => Http::response(['choices' => [['message' => ['content' => 'Buka menu SPP.']]]])]);
        $this->actingAs($staf)->postJson('/bantuan/tanya', ['pertanyaan' => 'cara terbitkan spp bayar?', 'layar' => 'KEPENDIDIKAN › Administrasi › SPP'])
            ->assertOk()->assertJsonPath('sumber', 'groq')->assertJsonPath('sisa', 1)
            ->assertJsonPath('artikel.0.judul', 'Bagaimana cara menerbitkan SPP bulanan?');
        Http::assertSent(function (KirimanHttp $k) {
            $sistem = $k->data()['messages'][0]['content'];

            return str_contains($sistem, 'STAF aplikasi')
                && str_contains($sistem, 'sedang membuka layar: KEPENDIDIKAN › Administrasi › SPP')
                && str_contains($sistem, 'Buka KEPENDIDIKAN › Administrasi › SPP')
                && ! str_contains($sistem, 'Bagaimana cara membayar tagihan SPP?'); // artikel wali tak ikut
        });
        $log = DB::table('percakapan_asisten')->first();
        $this->assertSame([null, $staf->id_pengguna, 'staf', 'KEPENDIDIKAN › Administrasi › SPP'], [$log->id_wali, $log->id_pengguna, $log->audiens, $log->layar]);

        $this->actingAs($staf)->getJson('/bantuan/percakapan')->assertOk()->assertJsonPath('sisa', 1)
            ->assertJsonPath('percakapan.0.teks', 'cara terbitkan spp bayar?');

        // Batas staf terpisah dari batas wali & per orang.
        $this->actingAs($staf)->postJson('/bantuan/tanya', ['pertanyaan' => 'lagi'])->assertJsonPath('sisa', 0);
        $this->actingAs($staf)->postJson('/bantuan/tanya', ['pertanyaan' => 'lagi'])->assertStatus(429);
        $this->assertSame(20, AsistenWali::sisaHariIni($this->wali));

        // Artikel wali tetap hanya untuk wali.
        $this->assertCount(0, AsistenWali::cariArtikel('menerbitkan spp', 5, 'wali')->where('audiens', 'staf'));
        $this->atur(['aktif' => true]);
        $this->sebagaiWali()->get('/wali/bantuan')->assertOk()->assertDontSee('Bagaimana cara menerbitkan SPP bulanan?');
    }

    /** Angka di ikon aplikasi portal (12 Nov 2026) = kabar belum dibaca orang yang masuk. */
    public function test_angka_ikon_portal_sama_dengan_kabar_belum_dibaca(): void
    {
        foreach (['ibu', 'ibu', 'ayah'] as $p) {
            \App\Models\NotifikasiWali::create(['id_wali' => $this->wali->id, 'peran' => $p, 'judul' => 'x', 'pesan' => 'x', 'jenis' => 'pin_terkunci']);
        }
        $this->sebagaiWali()->get('/wali/akun')->assertOk()->assertSee('navigator.setAppBadge(2)', false);
        $this->sebagaiWali()->get('/wali/kabar')->assertOk(); // dibuka = dibaca
        $this->sebagaiWali()->get('/wali/akun')->assertOk()->assertSee('0 > 0 ? navigator.setAppBadge(0) : navigator.clearAppBadge()', false);
    }

    public function test_urai_berkas_artikel(): void
    {
        $md = "Pengantar singkat.\n\n## Bagaimana cara masuk portal?\nkata kunci: masuk, login, otp\n\n1. Buka portal.\n2. Masukkan email.\n\n---\n\n## Cara ganti PIN?\nIsi tanpa kata kunci. [PERLU DICEK: jalur menu]\n";
        $a = ImporArtikelBantuan::urai($md);
        $this->assertCount(2, $a);
        $this->assertSame(['judul' => 'Bagaimana cara masuk portal?', 'kata_kunci' => 'masuk, login, otp', 'isi' => "1. Buka portal.\n2. Masukkan email."], $a[0]);
        $this->assertNull($a[1]['kata_kunci']);

        $berkas = tempnam(sys_get_temp_dir(), 'artikel');
        file_put_contents($berkas, $md);
        $this->artisan('bantuan:impor-artikel', ['berkas' => $berkas, '--jalankan' => true])->assertFailed();
        $this->artisan('bantuan:impor-artikel', ['berkas' => $berkas, '--paksa' => true])->assertSuccessful();
        $this->assertDatabaseMissing('artikel_bantuan', ['judul' => 'Cara ganti PIN?']);
        $this->artisan('bantuan:impor-artikel', ['berkas' => $berkas, '--paksa' => true, '--jalankan' => true])->assertSuccessful();
        $this->assertDatabaseHas('artikel_bantuan', ['audiens' => 'wali', 'judul' => 'Bagaimana cara masuk portal?', 'kata_kunci' => 'masuk, login, otp']);
        // Judul sama untuk pembaca lain = artikel lain.
        $this->artisan('bantuan:impor-artikel', ['berkas' => $berkas, '--paksa' => true, '--jalankan' => true, '--audiens' => 'staf'])->assertSuccessful();
        $this->assertSame(2, DB::table('artikel_bantuan')->where('judul', 'Bagaimana cara masuk portal?')->count());
        $this->artisan('bantuan:impor-artikel', ['berkas' => $berkas, '--audiens' => 'tamu'])->assertFailed();
        unlink($berkas);
    }
}
