<?php

namespace Tests\Feature;

use App\Models\Jenjang;
use App\Models\Level;
use App\Models\Santri;
use App\Models\User;
use App\Models\VerifikasiWali;
use App\Models\Wali;
use App\Notifications\KodeMasukWali;
use App\Services\PortalWali\LinkVerifikasi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Verifikasi data wali lewat link WhatsApp (keputusan user 14 Okt 2026): link
 * berlaku 30 hari sejak tanggal mulai pilihan staf, mode verifikasi menutup dompet
 * & pembayaran, email WAJIB terbukti, jalur cadangan NIS + tgl lahir + 4 angka HP.
 */
class VerifikasiWaliTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Wali $wali;

    private Santri $ahmad;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        RateLimiter::clear('verifikasi-cadangan:2425001');
        (new LinkVerifikasi)->aturAktif(true, null);

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->admin = User::create(['username' => 'zzvw_admin', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif']);
        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'jumlah_tingkat' => 3]);

        $this->wali = Wali::create(['kontak_utama' => 'ibu', 'nama_ibu' => 'Ibu Aminah', 'telepon_ibu' => '081234567890',
            'email_ibu' => 'aminah@contoh.id', 'nama_ayah' => 'Bapak Hasan',
            'nama' => 'Ibu Aminah', 'telepon' => '081234567890', 'status' => 'aktif']);
        $this->ahmad = Santri::create(['no_pendaftaran' => 'ZZVW-1', 'nis' => '2425001', 'nama' => 'Ahmad', 'jenis_kelamin' => 'L',
            'tanggal_lahir' => '2013-05-02', 'kode_jenjang' => 'SMP', 'jalur' => 'reguler', 'status' => 'aktif', 'id_wali' => $this->wali->id]);
    }

    private function link(?Carbon $mulai = null): VerifikasiWali
    {
        return (new LinkVerifikasi)->buat($this->wali->id, $mulai ?? now(), $this->admin->id_pengguna);
    }

    /** Buka link + pilih peran + NIS salah satu anak (verifikasi dua langkah) → sesi mode verifikasi. */
    private function masukLewatLink(VerifikasiWali $v, string $peran = 'ibu'): void
    {
        $kode = LinkVerifikasi::kode($v);
        $this->get(route('portal.verifikasi.buka', $kode))->assertOk()->assertSee('Ibu Aminah')->assertSee('NIS salah satu anak');
        $this->post(route('portal.verifikasi.buka', $kode), ['peran' => $peran, 'nis' => '2425001'])
            ->assertRedirect(route('portal.verifikasi.ringkasan'));
    }

    private function kodeTerkirimKe(string $email): string
    {
        $kode = '';
        Notification::assertSentOnDemand(KodeMasukWali::class, function (KodeMasukWali $n, array $s, AnonymousNotifiable $t) use ($email, &$kode) {
            return $t->routes['mail'] === $email && ($kode = $n->kode) !== '';
        });

        return $kode;
    }

    // ---- Staf ----

    public function test_buat_massal_hanya_keluarga_yang_perlu_dan_belum_punya_link(): void
    {
        $alumni = Wali::create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Alumni', 'telepon_ayah' => '0822', 'nama' => 'Alumni', 'telepon' => '0822', 'status' => 'aktif']);
        Santri::create(['no_pendaftaran' => 'ZZVW-2', 'nama' => 'Lulus', 'jenis_kelamin' => 'L', 'kode_jenjang' => 'SMP',
            'jalur' => 'reguler', 'status' => 'alumni', 'id_wali' => $alumni->id]);

        $this->actingAs($this->admin)->post(route('wali.verifikasi.massal'), ['mulai' => '2026-11-01'])->assertSessionHas('status');
        $v = VerifikasiWali::sole();
        $this->assertSame($this->wali->id, $v->id_wali);
        $this->assertSame('2026-11-01', $v->berlaku_mulai->format('Y-m-d'));
        $this->assertSame('2026-12-01', $v->berlaku_sampai->format('Y-m-d'), '30 hari sejak tanggal mulai pilihan staf');

        // Ditekan lagi: link yang sudah ada tak disentuh.
        $this->actingAs($this->admin)->post(route('wali.verifikasi.massal'), ['mulai' => '2026-11-05']);
        $this->assertSame(1, VerifikasiWali::count());

        $this->actingAs($this->admin)->get(route('wali.verifikasi'))->assertOk()->assertSee('Ibu Aminah')->assertDontSee('Lulus');
    }

    public function test_kirim_wa_menandai_terkirim_dan_membuka_wa_me(): void
    {
        $v = $this->link();
        $res = $this->actingAs($this->admin)->get(route('wali.verifikasi.wa', $v->id));

        $this->assertStringStartsWith('https://wa.me/6281234567890?text=', $res->headers->get('Location'));
        $this->assertStringContainsString(rawurlencode(LinkVerifikasi::url($v)), $res->headers->get('Location'));
        $this->assertNotNull($v->fresh()->dikirim_pada);
    }

    public function test_link_baru_mencabut_yang_lama(): void
    {
        $lama = $this->link();
        $baru = $this->link();

        $this->assertNotNull($lama->fresh()->dicabut_pada);
        $this->get(route('portal.verifikasi.buka', LinkVerifikasi::kode($lama)))->assertSee('sudah diganti');
        $this->get(route('portal.verifikasi.buka', LinkVerifikasi::kode($baru)))->assertSee('Anda membuka sebagai');
    }

    // ---- Wali ----

    public function test_link_belum_mulai_dan_kedaluwarsa_ditolak(): void
    {
        $v = $this->link(now()->addDays(3));
        $this->get(route('portal.verifikasi.buka', LinkVerifikasi::kode($v)))->assertSee('baru bisa dibuka mulai')->assertDontSee('Anda membuka sebagai');

        $v->update(['berlaku_mulai' => now()->subDays(40), 'berlaku_sampai' => now()->subDays(10)]);
        $this->get(route('portal.verifikasi.buka', LinkVerifikasi::kode($v)))->assertSee('Masa berlaku link ini sudah habis');
    }

    public function test_mode_verifikasi_menutup_dompet_dan_pembayaran(): void
    {
        $this->masukLewatLink($this->link());

        $this->get(route('portal.verifikasi.ringkasan'))->assertOk()->assertSee('AHMAD')->assertSee('aminah@contoh.id');
        $this->get(route('portal.data'))->assertOk();
        foreach (['portal.beranda', 'portal.dompet', 'portal.tagihan', 'portal.akun', 'portal.pin'] as $rute) {
            $this->get(route($rute))->assertRedirect(route('portal.verifikasi.ringkasan'));
        }
    }

    public function test_link_dicabut_di_tengah_jalan_menutup_sesi(): void
    {
        $v = $this->link();
        $this->masukLewatLink($v);
        (new LinkVerifikasi)->cabut($v);

        $this->get(route('portal.data'))->assertRedirect(route('portal.masuk'));
        $this->assertGuest('wali');
    }

    public function test_alur_lengkap_buktikan_email_lalu_selesai_menjadi_akun_portal(): void
    {
        $v = $this->link();
        $this->masukLewatLink($v);

        // Email WAJIB terbukti sebelum selesai.
        $this->post(route('portal.verifikasi.selesai'), ['setuju' => '1'])->assertSessionHas('error');
        $this->assertNull($v->fresh()->selesai_pada);

        $this->post(route('portal.verifikasi.email'))->assertRedirect(route('portal.verifikasi.kode'));
        $kode = $this->kodeTerkirimKe('aminah@contoh.id');
        $this->post(route('portal.verifikasi.kode'), ['kode' => $kode])->assertRedirect(route('portal.verifikasi.ringkasan'));

        // Password sekalian dibuat di langkah Selesai (keputusan user 14 Okt 2026).
        $this->post(route('portal.verifikasi.selesai'), ['setuju' => '1', 'sandi' => 'pendek', 'sandi_ulang' => 'pendek'])
            ->assertSessionHas('error');
        $this->post(route('portal.verifikasi.selesai'), ['setuju' => '1', 'sandi' => 'rahasia123', 'sandi_ulang' => 'rahasia123'])
            ->assertRedirect(route('portal.verifikasi.terima_kasih'));
        $this->assertTrue(\App\Services\PortalWali\KataSandiWali::punya($this->wali, 'ibu'));
        $v->refresh();
        $this->assertNotNull($v->selesai_pada);
        $this->assertSame('ibu', $v->selesai_peran);
        $this->assertSame('aminah@contoh.id', $v->selesai_email);

        // Halaman penutup, bukan aplikasi: ringkasan + tombol Tutup, sesinya berakhir.
        $this->get(route('portal.verifikasi.terima_kasih'))->assertOk()
            ->assertSee('Syukron, pembaharuan data telah selesai')->assertSee('AHMAD')
            ->assertSee('Ibu Aminah')->assertSee('aminah@contoh.id')->assertSee('081234567890')->assertSee('Tutup');
        $this->assertGuest('wali');
        $this->get(route('portal.beranda'))->assertRedirect(route('portal.masuk'));
        $this->get(route('portal.verifikasi.buka', LinkVerifikasi::kode($v)))->assertSee('sudah selesai');
    }

    public function test_peran_tanpa_email_wajib_mengisi_email_baru(): void
    {
        $this->masukLewatLink($this->link(), 'ayah');
        $this->get(route('portal.verifikasi.ringkasan'))->assertSee('Belum ada email tercatat');
        $this->post(route('portal.verifikasi.email'))->assertSessionHas('error');

        // Mengisi email lewat Data Wali & Santri = terbukti (kode dikirim ke alamat baru).
        $this->post(route('portal.data.email', 'ayah'), ['email' => 'hasan@contoh.id'])->assertRedirect(route('portal.data.email.kode'));
        $kode = $this->kodeTerkirimKe('hasan@contoh.id');
        $this->post(route('portal.data.email.kode'), ['kode' => $kode])->assertRedirect(route('portal.verifikasi.ringkasan'));

        $this->post(route('portal.verifikasi.selesai'), ['setuju' => '1', 'sandi' => 'rahasia123', 'sandi_ulang' => 'rahasia123'])
            ->assertRedirect(route('portal.verifikasi.terima_kasih'));
        $this->assertSame('hasan@contoh.id', $this->wali->fresh()->email_ayah);
    }

    // ---- Verifikasi dua langkah (NIS) ----

    public function test_nis_salah_ditolak_dan_dibatasi_per_link(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $v = $this->link();
        $kode = LinkVerifikasi::kode($v);
        RateLimiter::clear('verifikasi-nis:'.$v->id);

        $this->post(route('portal.verifikasi.buka', $kode), ['peran' => 'ibu'])->assertSessionHasErrors('nis');
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('portal.verifikasi.buka', $kode), ['peran' => 'ibu', 'nis' => '9999999'])
                ->assertSessionHas('error', fn ($e) => str_contains($e, 'NIS tidak cocok'));
        }
        // Ke-6: diblokir walau NIS-nya kini benar.
        $this->post(route('portal.verifikasi.buka', $kode), ['peran' => 'ibu', 'nis' => '2425001'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'Terlalu banyak percobaan'));
        $this->assertGuest('wali');
    }

    public function test_keluarga_tanpa_nis_atau_calon_melewati_langkah_nis(): void
    {
        $this->ahmad->update(['status' => 'calon']);
        $kode = LinkVerifikasi::kode($this->link());

        $this->get(route('portal.verifikasi.buka', $kode))->assertOk()->assertDontSee('NIS salah satu anak');
        $this->post(route('portal.verifikasi.buka', $kode), ['peran' => 'ibu'])->assertRedirect(route('portal.verifikasi.ringkasan'));
    }

    // ---- Nama santri dikunci ----

    public function test_nama_santri_hanya_bisa_diubah_admin(): void
    {
        $staf = User::create(['username' => 'zzvw_staf', 'nama' => 'Staf', 'password_hash' => 'x', 'kode_level' => 'L1',
            'is_admin' => false, 'status' => 'aktif']);
        \App\Models\HakAksesModul::create(['id_pengguna' => $staf->id_pengguna, 'kode_modul' => 'santri',
            'lihat' => true, 'buat' => false, 'ubah' => true, 'hapus' => false, 'menu' => true]);
        $isian = ['id_wali' => $this->wali->id, 'nama' => 'Nama Diganti', 'jenis_kelamin' => 'L', 'kode_jenjang' => 'SMP', 'tingkat' => 1,
            'tanggal_lahir' => '2013-05-02'];

        $this->actingAs($staf)->get(route('santri.edit', $this->ahmad->id))->assertOk()->assertDontSee('name="nama"', false);
        $this->actingAs($staf)->put(route('santri.update', $this->ahmad->id), $isian)->assertRedirect(route('santri.show', $this->ahmad->id));
        $this->assertSame('AHMAD', $this->ahmad->fresh()->nama, 'staf non-admin: nama diabaikan');

        $this->actingAs($this->admin)->put(route('santri.update', $this->ahmad->id), $isian);
        $this->assertSame('NAMA DIGANTI', $this->ahmad->fresh()->nama);
    }

    public function test_salin_pesan_berisi_teks_wa_lengkap(): void
    {
        $this->link();
        // Frasa penutup pesan WA hanya muncul di halaman staf lewat tombol salin.
        $this->actingAs($this->admin)->get(route('wali.verifikasi'))->assertOk()
            ->assertSee('Salin pesan')->assertSee('Jazakumullah khairan')->assertSee('mohon tidak dibagikan');
    }

    public function test_email_kembar_tak_bisa_dibuktikan(): void
    {
        Wali::create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Lain', 'telepon_ayah' => '0833', 'email_ayah' => 'aminah@contoh.id',
            'nama' => 'Lain', 'telepon' => '0833', 'status' => 'aktif']);
        $this->masukLewatLink($this->link());

        $this->get(route('portal.verifikasi.ringkasan'))->assertSee('juga tercatat di keluarga lain');
        $this->post(route('portal.verifikasi.email'))->assertSessionHas('error');
        Notification::assertNothingSent();
    }

    public function test_mode_verifikasi_tanpa_kabar_staf_tapi_jejak_tetap(): void
    {
        $this->masukLewatLink($this->link());
        $isi = ['nik' => '', 'alamat' => 'Jl. Baru 9'];
        foreach (['ayah', 'ibu', 'wali'] as $p) {
            $isi += ["nama_{$p}" => (string) $this->wali->{"nama_{$p}"}, "telepon_{$p}" => (string) $this->wali->{"telepon_{$p}"},
                "pekerjaan_{$p}" => '', "pekerjaan_lain_{$p}" => '', "pendapatan_{$p}" => ''];
        }
        $this->put(route('portal.data.keluarga'), $isi)->assertSessionHas('status');

        $this->assertSame('Jl. Baru 9', $this->wali->fresh()->alamat);
        $this->assertSame(0, \App\Models\Notification::where('jenis', \App\Services\PortalWali\UbahDataWali::JENIS_NOTIF)->count());
        $this->assertSame(1, \App\Models\ActivityLog::where('aksi', 'ubah_data_portal')->count());
    }

    // ---- Saklar & akhiri ----

    public function test_saklar_staf_menutup_dan_membuka_lagi_tanpa_mencabut_link(): void
    {
        $v = $this->link();
        $this->masukLewatLink($v);

        // Staf menonaktifkan lewat layar — tanpa SSH.
        $this->actingAs($this->admin)->post(route('wali.verifikasi.saklar'), ['aktif' => 0])->assertSessionHas('status');
        $this->assertFalse(LinkVerifikasi::aktif());
        $this->assertSame($this->admin->id_pengguna, LinkVerifikasi::pengaturan()->diubah_oleh);

        $this->get(route('portal.data'))->assertRedirect(route('portal.masuk'));
        $this->assertGuest('wali');
        $this->get(route('portal.verifikasi.buka', LinkVerifikasi::kode($v)))->assertSee('sedang tidak dibuka');
        $this->get(route('portal.verifikasi.cari'))->assertSee('sedang tidak dibuka');
        $this->actingAs($this->admin)->post(route('wali.verifikasi.massal'), ['mulai' => now()->format('Y-m-d')])->assertSessionHas('error');
        $this->actingAs($this->admin)->get(route('wali.verifikasi'))->assertOk()->assertSee('NONAKTIF')->assertSee('Aktifkan');
        $this->assertNull($v->fresh()->dicabut_pada, 'nonaktif ≠ dicabut');

        // Diaktifkan lagi: link yang sama hidup kembali.
        $this->actingAs($this->admin)->post(route('wali.verifikasi.saklar'), ['aktif' => 1]);
        $this->get(route('portal.verifikasi.buka', LinkVerifikasi::kode($v)))->assertSee('Anda membuka sebagai');
        // Dua tekanan staf tercatat (yang di setUp tanpa pelaku).
        $this->assertSame(2, \App\Models\ActivityLog::where('aksi', 'like', 'verifikasi_wali_%')
            ->where('id_pengguna', $this->admin->id_pengguna)->count());
    }

    public function test_keluarga_yang_selesai_bisa_diverifikasi_ulang(): void
    {
        $lama = $this->link();
        $lama->update(['selesai_pada' => now()->subDays(2), 'selesai_peran' => 'ibu', 'selesai_email' => 'aminah@contoh.id']);

        // Tombol massal biasa melewatinya; dengan "termasuk yang sudah selesai" ikut dibuatkan.
        $this->actingAs($this->admin)->post(route('wali.verifikasi.massal'), ['mulai' => now()->format('Y-m-d')]);
        $this->assertSame(1, VerifikasiWali::count());
        $this->actingAs($this->admin)->get(route('wali.verifikasi'))->assertSee('Verifikasi ulang');

        $this->actingAs($this->admin)->post(route('wali.verifikasi.massal'), ['mulai' => now()->format('Y-m-d'), 'ulang' => 1]);
        $baru = VerifikasiWali::whereNull('dicabut_pada')->sole();
        $this->assertNotSame($lama->id, $baru->id);
        $this->assertNotNull($lama->fresh()->selesai_pada, 'riwayat selesai tetap tersimpan');

        $this->actingAs($this->admin)->get(route('wali.verifikasi'))->assertSee('pernah selesai');
        $this->masukLewatLink($baru);
        $this->get(route('portal.verifikasi.ringkasan'))->assertOk();
    }

    public function test_akhiri_mencabut_yang_belum_selesai_saja(): void
    {
        $belum = $this->link();
        $lain = Wali::create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Selesai', 'telepon_ayah' => '0844', 'nama' => 'Selesai', 'telepon' => '0844', 'status' => 'aktif']);
        $selesai = (new LinkVerifikasi)->buat($lain->id, now(), null);
        $selesai->update(['selesai_pada' => now(), 'selesai_peran' => 'ayah', 'selesai_email' => 'x@contoh.id']);

        $this->actingAs($this->admin)->post(route('wali.verifikasi.akhiri'))->assertSessionHas('status');

        $this->assertNotNull($belum->fresh()->dicabut_pada);
        $this->assertNull($selesai->fresh()->dicabut_pada, 'riwayat selesai dibiarkan');
    }

    // ---- Jalur cadangan ----

    public function test_cadangan_membuka_link_yang_sedang_berlaku(): void
    {
        $v = $this->link();

        $this->post(route('portal.verifikasi.cari'), ['nis' => '2425001', 'tanggal_lahir' => '2013-05-02', 'hp' => '7890'])
            ->assertRedirect(route('portal.verifikasi.buka', LinkVerifikasi::kode($v)));
    }

    public function test_cadangan_salah_atau_tanpa_link_pesannya_seragam_dan_dibatasi(): void
    {
        // Batas per-IP rute (throttle:5,1) dimatikan: yang diuji di sini batas per-NIS,
        // yang tetap berlaku walau penebak berganti jaringan.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        // Benar semua, tapi keluarga belum dikirimi link → pesan yang SAMA dengan isian salah.
        $tanpaLink = $this->post(route('portal.verifikasi.cari'), ['nis' => '2425001', 'tanggal_lahir' => '2013-05-02', 'hp' => '7890']);
        $pesan = session('error');
        $this->assertNotNull($pesan);

        $this->link();
        $this->post(route('portal.verifikasi.cari'), ['nis' => '2425001', 'tanggal_lahir' => '2013-05-03', 'hp' => '7890'])
            ->assertSessionHas('error', $pesan);
        $this->post(route('portal.verifikasi.cari'), ['nis' => '2425001', 'tanggal_lahir' => '2013-05-02', 'hp' => '1111'])
            ->assertSessionHas('error', $pesan);

        for ($i = 0; $i < 3; $i++) {
            $this->post(route('portal.verifikasi.cari'), ['nis' => '2425001', 'tanggal_lahir' => '2013-05-02', 'hp' => '1111']);
        }
        // Percobaan ke-6: diblokir, walau kini isiannya benar.
        $this->post(route('portal.verifikasi.cari'), ['nis' => '2425001', 'tanggal_lahir' => '2013-05-02', 'hp' => '7890'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'Terlalu banyak percobaan'));
    }
}
