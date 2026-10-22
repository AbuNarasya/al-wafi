<?php

namespace Tests\Feature;

use App\Models\Jenjang;
use App\Models\Santri;
use App\Models\Wali;
use App\Services\PortalWali\KataSandiWali;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PORTAL WALI TIGA BAHASA (keputusan user 22 Okt 2026): Indonesia, English, العربية — hanya portal wali.
 * Lihat App\Support\BahasaPortal.
 */
class TigaBahasaPortalTest extends TestCase
{
    use RefreshDatabase;

    /** Berkas yang teksnya bisa sampai ke wali — setiap kunci `__('…')` di sini wajib punya terjemahan en & ar. */
    private const SUMBER = [
        'resources/views/portal', 'resources/views/layouts/portal.blade.php', 'resources/views/components/portal',
        'app/Http/Controllers/Portal*.php', 'app/Services/PortalWali', 'app/Notifications/KodeMasukWali.php',
        'app/Notifications/EmailWaliDiganti.php',
        // Tahap 2: kalimat kabar wali (Teks::k) di mana pun pengirimnya berada.
        'app/Services', 'app/Http/Controllers/DokumenSantriController.php', 'app/Models/MutasiDompet.php',
    ];

    /** @return array<string,list<string>> kunci → berkas tempat ia dipakai */
    private function kunciDipakai(): array
    {
        $berkas = [];
        foreach (self::SUMBER as $pola) {
            $jalur = base_path($pola);
            if (is_dir($jalur)) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($jalur, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    $berkas[] = $f->getPathname();
                }
            } else {
                array_push($berkas, ...glob($jalur));
            }
        }
        $kunci = [];
        foreach ($berkas as $f) {
            // Hanya kunci LITERAL: satu atau beberapa '…' yang disambung `.` lalu diakhiri `,` atau `)`.
            // Kunci dinamis (mis. 'Data '.ucfirst($peran)) dilewati — nilainya dijaga lewat fragmen kelompoknya.
            $lit = "'(?:[^'\\\\]|\\\\.)*'";
            preg_match_all("/(?:__|Teks::k)\(\s*((?:$lit\s*\.\s*)*$lit)\s*[,)]/", file_get_contents($f), $m);
            foreach ($m[1] as $potongan) {
                preg_match_all("/$lit/", $potongan, $p);
                $k = implode('', array_map(fn ($s) => stripslashes(substr($s, 1, -1)), $p[0]));
                $kunci[$k][] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $f);
            }
        }

        return $kunci;
    }

    public function test_setiap_teks_portal_punya_terjemahan_inggris_dan_arab(): void
    {
        $kunci = $this->kunciDipakai();
        $this->assertGreaterThan(300, count($kunci), 'portal seharusnya sudah dibungkus __() secara menyeluruh');
        foreach (['en', 'ar'] as $b) {
            $kamus = json_decode((string) file_get_contents(lang_path("$b.json")), true, flags: JSON_THROW_ON_ERROR);
            $hilang = array_diff_key($kunci, $kamus);
            $this->assertSame([], array_map(fn ($f) => array_values(array_unique($f))[0], $hilang), "kunci tanpa terjemahan $b");

            foreach ($kamus as $k => $v) {
                preg_match_all('/:([a-z_]+)/i', $k, $a);
                // Tak peka huruf: `:Peran` = placeholder yang sama dengan huruf depan kapital (fitur Laravel).
                foreach (array_unique($a[1]) as $p) {
                    $this->assertStringContainsStringIgnoringCase(":$p", $v, "placeholder :$p hilang di terjemahan $b untuk \"$k\"");
                }
            }
        }
    }

    /** Tahap 2: kabar disusun per orang dalam bahasanya; staf tetap Indonesia. */
    public function test_kabar_disusun_per_orang_dalam_bahasanya_dan_staf_tetap_indonesia(): void
    {
        $w = Wali::create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Ayah', 'email_ayah' => 'a@contoh.id', 'telepon_ayah' => '081200000001',
            'nama_ibu' => 'Ibu', 'email_ibu' => 'i@contoh.id', 'nama' => 'Ayah', 'telepon' => '081200000001', 'status' => 'aktif']);
        DB::table('bahasa_wali')->insert(['id_wali' => $w->id, 'peran' => 'ayah', 'bahasa' => 'ar', 'created_at' => now(), 'updated_at' => now()]);

        \App\Services\PortalWali\KabarWali::keKeluarga($w, ['judul' => \App\Support\Teks::k('Kabar'),
            'pesan' => \App\Support\Teks::k('Kabar, :jumlah belum dibaca', ['jumlah' => 3]), 'jenis' => 'uji']);

        $baris = \App\Models\NotifikasiWali::where('id_wali', $w->id)->get()->keyBy('peran');
        $this->assertSame([__('Kabar', [], 'ar'), __('Kabar, :jumlah belum dibaca', ['jumlah' => 3], 'ar')], [$baris['ayah']->judul, $baris['ayah']->pesan]);
        $this->assertSame(['Kabar', 'Kabar, 3 belum dibaca'], [$baris['ibu']->judul, $baris['ibu']->pesan]);
        $this->assertNotSame($baris['ibu']->pesan, $baris['ayah']->pesan);

        // Ke staf pencatat: selalu Indonesia.
        \App\Models\Level::create(['kode_level' => 'L1', 'nama_level' => 'Staf', 'max_transaksi' => null]);
        $staf = \App\Models\User::create(['username' => 'stf', 'nama' => 'Staf', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif']);
        \App\Services\PortalWali\KabarWali::kePencatat((object) ['dicatat_oleh' => $staf->id_pengguna, 'dicatat_oleh_wali' => null, 'dicatat_oleh_peran' => null],
            ['judul' => \App\Support\Teks::k('Kabar'), 'pesan' => \App\Support\Teks::k('Kabar, :jumlah belum dibaca', ['jumlah' => 2]), 'jenis' => 'uji']);
        $this->assertSame('Kabar, 2 belum dibaca', \App\Models\Notification::where('id_pengguna', $staf->id_pengguna)->value('pesan'));
    }

    public function test_pilih_bahasa_sebelum_masuk_arab_kanan_ke_kiri_dan_layar_staf_tetap_indonesia(): void
    {
        $this->get(route('portal.masuk'))->assertOk()->assertSee('dir="ltr"', false)->assertSee('lang="id"', false);

        $this->post(route('portal.bahasa'), ['bahasa' => 'ar'])->assertRedirect();
        $this->get(route('portal.masuk'))->assertSee('dir="rtl"', false)->assertSee('lang="ar"', false)
            ->assertSee(__('Portal Wali', locale: 'ar'));

        $this->post(route('portal.bahasa'), ['bahasa' => 'en']);
        $this->get(route('portal.masuk'))->assertSee('dir="ltr"', false)->assertSee('lang="en"', false)->assertSee('Parent Portal');

        // Locale portal tak bocor ke layar staf.
        $this->get('/login')->assertOk()->assertDontSee('Parent Portal');
        $this->assertSame('id', app()->getLocale());

        $this->post(route('portal.bahasa'), ['bahasa' => 'fr'])->assertSessionHasErrors('bahasa');
    }

    private function masukSebagaiIbu(): array
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'jumlah_tingkat' => 3]);
        $w = Wali::create(['kontak_utama' => 'ibu', 'nama_ibu' => 'Ibu Aminah', 'telepon_ibu' => '081234567891', 'email_ibu' => 'i@contoh.id',
            'nama_ayah' => 'Ayah', 'nama' => 'Ibu Aminah', 'telepon' => '081234567891', 'status' => 'aktif']);
        $s = Santri::create(['no_pendaftaran' => 'TB-1', 'nis' => '2627001', 'nama' => 'Ahmad', 'jenis_kelamin' => 'L', 'kode_jenjang' => 'SMP',
            'jalur' => 'reguler', 'status' => 'aktif', 'id_wali' => $w->id]);
        (new KataSandiWali)->simpan($w, 'ibu', 'sandiUji12345', 'sandiUji12345');
        $this->post(route('portal.masuk.sandi'), ['email' => 'i@contoh.id', 'sandi' => 'sandiUji12345']);

        return [$w, $s];
    }

    public function test_pilihan_bahasa_tersimpan_per_orang_dan_ikut_ke_sesi_baru(): void
    {
        [$w] = $this->masukSebagaiIbu();
        $this->post(route('portal.bahasa'), ['bahasa' => 'ar']);
        $this->assertSame('ar', DB::table('bahasa_wali')->where('id_wali', $w->id)->where('peran', 'ibu')->value('bahasa'));

        // Perangkat lain: sesi baru tanpa pilihan → diambil dari server begitu masuk.
        $this->flushSession();
        $this->post(route('portal.masuk.sandi'), ['email' => 'i@contoh.id', 'sandi' => 'sandiUji12345']);
        $this->get(route('portal.beranda'))->assertOk()->assertSee('dir="rtl"', false);
    }

    public function test_halaman_utama_portal_tampil_di_tiga_bahasa(): void
    {
        [, $s] = $this->masukSebagaiIbu();
        $halaman = [route('portal.beranda'), route('portal.tagihan'), route('portal.akun'), route('portal.data'),
            route('portal.kabar'), route('portal.menu'), route('portal.anak', $s->id), route('portal.dompet')];

        foreach (['id', 'en', 'ar'] as $b) {
            $this->post(route('portal.bahasa'), ['bahasa' => $b]);
            foreach ($halaman as $url) {
                $r = $this->get($url);
                $this->assertSame(200, $r->status(), "$b $url");
                $r->assertSee('lang="'.$b.'"', false);
            }
        }
        // Di akun ada pemilih bahasa.
        $this->get(route('portal.akun'))->assertSee('العربية')->assertSee('English')->assertSee('Bahasa Indonesia');
    }
}
