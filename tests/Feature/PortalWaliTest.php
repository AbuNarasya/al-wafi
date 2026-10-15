<?php

namespace Tests\Feature;

use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
use App\Models\Level;
use App\Models\Pendaftaran;
use App\Models\PerangkatWali;
use App\Models\PersetujuanTerm;
use App\Models\Santri;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Models\Wali;
use App\Notifications\KodeMasukWali;
use App\Services\Modules\JenisBiayaService;
use App\Services\Modules\SuratPernyataanService;
use App\Services\Modules\TarifService;
use App\Services\PortalWali\IngatPerangkat;
use App\Services\PortalWali\MasukWali;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\MembuatGelombang;
use Tests\TestCase;

/**
 * Portal Wali: masuk dengan email + kode, pendaftaran mandiri, beranda.
 */
class PortalWaliTest extends TestCase
{
    use MembuatGelombang;
    use RefreshDatabase;

    private const TA = '2027/2028';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        RateLimiter::clear('portal-otp:ip:127.0.0.1');

        CoaGroup::create(['kode_grup' => 'ZZPT', 'nama_grup' => 'Portal']);
        CoaDetail::create(['kode_coa' => '4.ZZPT.1', 'nama_coa' => 'Pendapatan', 'kode_grup' => 'ZZPT', 'jenis_saldo' => 'kredit']);
        BusinessUnit::create(['kode_unit' => 'ZZPTU', 'nama_unit' => 'Unit']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->admin = User::create(['username' => 'zzpt_adm', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif']);

        TahunAjaran::create(['kode' => self::TA, 'status' => 'aktif']);
        Jenjang::create(['kode' => 'J001', 'nama' => 'SDTQ', 'urutan' => 1, 'jumlah_tingkat' => 6]);
        JalurPendaftaran::create(['kode' => '001', 'nama' => 'Reguler', 'tampil_di_portal' => true]);
        JalurPendaftaran::create(['kode' => '005', 'nama' => 'Anak Karyawan', 'tampil_di_portal' => false]);
        $this->buatGelombang(self::TA, 'G1', ['nama' => 'Gelombang 1']);

        (new JenisBiayaService)->create(['kode' => 'REG-J001', 'nama' => 'Registrasi SDTQ', 'tipe' => 'registrasi',
            'kode_jenjang' => 'J001', 'kode_coa_pendapatan' => '4.ZZPT.1', 'kode_unit' => 'ZZPTU']);
        (new TarifService)->simpan(self::TA, 'J001', ['-' => ['registrasi' => ['nominal' => '500000']]]);

        (new SuratPernyataanService)->create(['judul' => 'Surat Pernyataan', 'isi' => 'Patuh tata tertib.',
            'berlaku_mulai' => '2026-10-01'], $this->admin->id_pengguna);
    }

    /** Minta kode lewat layar, lalu kembalikan kode yang "terkirim". */
    private function mintaKode(string $email): string
    {
        $this->post(route('portal.masuk'), ['email' => $email])->assertRedirect(route('portal.kode'));

        $kode = null;
        Notification::assertSentOnDemand(KodeMasukWali::class, function (KodeMasukWali $n, array $saluran, AnonymousNotifiable $tujuan) use ($email, &$kode) {
            if ($tujuan->routes['mail'] === MasukWali::normalEmail($email)) {
                $kode = $n->kode;
            }

            return true;
        });

        return (string) $kode;
    }

    protected function masukSebagai(string $email, bool $ingat = false)
    {
        $kode = $this->mintaKode($email);

        return $this->post(route('portal.kode'), ['kode' => $kode, 'ingat' => $ingat ? '1' : null]);
    }

    /** Sesudah masuk dengan kode PERTAMA KALI: wajib membuat password (keputusan user 14 Okt 2026). */
    private function buatSandi(string $sandi = 'rahasia123')
    {
        return $this->post(route('portal.sandi.buat'), ['sandi' => $sandi, 'sandi_ulang' => $sandi]);
    }

    private function keluarga(array $kolom = []): Wali
    {
        return Wali::create($kolom + [
            'kontak_utama' => 'ayah', 'nama_ayah' => 'Bapak Hasan', 'telepon_ayah' => '081211110000',
            'email_ayah' => 'hasan@contoh.id', 'nama_ibu' => 'Ibu Hasanah', 'email_ibu' => 'hasanah@contoh.id',
            'nama' => 'Bapak Hasan', 'telepon' => '081211110000', 'status' => 'aktif',
        ]);
    }

    protected function isianDaftar(array $timpa = []): array
    {
        return $timpa + [
            'tahun_ajaran' => self::TA, 'gelombang' => 'G1', 'versi_surat' => 1, 'setuju' => '1',
            'nama' => 'Ahmad Faqih', 'jenis_kelamin' => 'L', 'kode_jenjang' => 'J001', 'tingkat' => 1, 'jalur' => '001',
            'peran' => 'ibu', 'nama_ibu' => 'Ibu Aminah', 'telepon_ibu' => '+62 812-9999-0001',
            'nama_ayah' => 'Bapak Faqih',
        ];
    }

    // ---- Halaman depan ----

    public function test_halaman_depan_dua_pintu_dan_yang_sudah_masuk_ke_beranda(): void
    {
        $this->get(route('portal.depan'))->assertOk()
            ->assertSee('Masuk Aplikasi')->assertSee('Buat Akun &amp; Daftarkan Calon Santri', false)
            ->assertSee(route('portal.masuk'), false)->assertSee(route('portal.daftar_baru'), false);
        $this->get(route('portal.daftar_baru'))->assertOk()->assertSee('Kirim kode ke email');
        $this->get(route('portal.masuk'))->assertOk()->assertSee('Belum punya akun?');

        $wali = $this->keluarga();
        $this->actingAs($wali, 'wali')->withSession(['portal.peran' => 'ayah', 'portal.punya_sandi' => true])
            ->get(route('portal.depan'))->assertRedirect(route('portal.beranda'));
    }

    /** Halaman depan = alamat awal aplikasi terpasang: perangkat yang diingat langsung masuk. */
    public function test_halaman_depan_memulihkan_perangkat_yang_diingat(): void
    {
        $wali = $this->keluarga();
        PerangkatWali::create(['id_wali' => $wali->id, 'peran' => 'ayah', 'token_hash' => hash('sha256', 'TOKEN-DEPAN'),
            'terakhir_dipakai' => now(), 'kedaluwarsa_pada' => null]);

        $this->withCookie(IngatPerangkat::COOKIE, 'TOKEN-DEPAN')->get(route('portal.depan'))
            ->assertRedirect(route('portal.beranda'));
        $this->assertSame('ayah', session('portal.peran'));
    }

    // ---- Masuk ----

    public function test_wali_terdaftar_masuk_dengan_kode_dan_melihat_anaknya(): void
    {
        $wali = $this->keluarga();
        Santri::create(['no_pendaftaran' => 'PSB-X1', 'nama' => 'Anak Hasan', 'jenis_kelamin' => 'L',
            'kode_jenjang' => 'J001', 'jalur' => '001', 'status' => 'terverifikasi', 'id_wali' => $wali->id]);

        // Email ibu, huruf besar & spasi — tetap dikenali sebagai IBU di keluarga ini.
        // Pertama kali masuk dengan kode → wajib membuat password dulu.
        $this->masukSebagai('  HASANAH@contoh.id ')->assertRedirect(route('portal.sandi.buat'));
        $this->get(route('portal.beranda'))->assertRedirect(route('portal.sandi.buat'));
        $this->buatSandi()->assertRedirect(route('portal.beranda'));

        $this->assertSame('ibu', session('portal.peran'));
        $this->get(route('portal.beranda'))->assertOk()
            ->assertSee('Ibu Hasanah')->assertSee('ANAK HASAN')->assertSee('Berkas Terverifikasi');
    }

    public function test_kode_salah_lima_kali_menghanguskan_kodenya(): void
    {
        $this->keluarga();
        $kode = $this->mintaKode('hasan@contoh.id');
        $salah = $kode === '000000' ? '111111' : '000000';

        for ($i = 0; $i < MasukWali::MAKS_SALAH; $i++) {
            $this->post(route('portal.kode'), ['kode' => $salah])->assertSessionHas('error');
        }
        // Kode yang benar pun sudah tak berlaku.
        $this->post(route('portal.kode'), ['kode' => $kode])->assertSessionHas('error');
        $this->assertNull(session('portal.peran'));
    }

    public function test_minta_kode_berulang_dijeda(): void
    {
        $this->post(route('portal.masuk'), ['email' => 'hasan@contoh.id']);
        $this->post(route('portal.masuk'), ['email' => 'hasan@contoh.id'])->assertSessionHas('error');

        Notification::assertSentOnDemandTimes(KodeMasukWali::class, 1);
    }

    /** Mode uji dev.erp: kode tampil di layar HANYA bila saklar menyala DAN surel mode log. */
    public function test_kode_tampil_di_layar_hanya_dalam_mode_uji(): void
    {
        $lihat = function (string $email) {
            $this->post(route('portal.masuk'), ['email' => $email]);

            return $this->get(route('portal.kode'))->assertOk();
        };

        // Bawaan: tidak pernah tampil.
        $lihat('a@contoh.id')->assertDontSee('Mode uji');

        // Saklar menyala tapi surel sungguhan (bukan log) — tetap tidak tampil.
        config(['app.otp_tampil_di_layar' => true, 'mail.default' => 'smtp']);
        $lihat('b@contoh.id')->assertDontSee('Mode uji');

        // Saklar + surel log: kode yang benar tampil.
        config(['mail.default' => 'log']);
        $layar = $lihat('c@contoh.id')->assertSee('Mode uji');
        $kode = null;
        Notification::assertSentOnDemand(KodeMasukWali::class, function (KodeMasukWali $n, array $s, AnonymousNotifiable $t) use (&$kode) {
            if ($t->routes['mail'] === 'c@contoh.id') {
                $kode = $n->kode;
            }

            return true;
        });
        $layar->assertSee($kode);
    }

    /** Satu pintu: email tak terdaftar tetap dikirimi kode — layar tak membocorkan siapa yang terdaftar. */
    public function test_email_baru_menjadi_pendaftar_bukan_wali(): void
    {
        $this->masukSebagai('baru@contoh.id')->assertRedirect(route('portal.daftar'));

        $this->get(route('portal.daftar'))->assertOk()->assertSee('Data keluarga')->assertSee('baru@contoh.id')->assertSee('Patuh tata tertib.');
        $this->get(route('portal.beranda'))->assertRedirect(route('portal.masuk'));
    }

    public function test_email_kembar_ditolak(): void
    {
        $this->keluarga();
        $this->keluarga(['telepon_ayah' => '081211112222', 'telepon' => '081211112222', 'email_ibu' => null]);

        $this->masukSebagai('hasan@contoh.id')->assertOk()->assertSee('lebih dari satu keluarga');
        $this->assertNull(session('portal.peran'));
    }

    public function test_sesi_wali_tidak_membuka_rute_staf(): void
    {
        $this->keluarga();
        $this->masukSebagai('hasan@contoh.id');

        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_ingat_perangkat_memulihkan_sesi_dan_gugur_saat_email_diubah(): void
    {
        $wali = $this->keluarga();
        $this->masukSebagai('hasan@contoh.id', ingat: true);
        $this->buatSandi();
        $perangkat = PerangkatWali::sole();
        $this->assertNull($perangkat->kedaluwarsa_pada, 'diingat selamanya');
        $this->assertSame('ayah', $perangkat->peran);

        // Token aslinya tak disimpan — buat ulang perangkat bertoken diketahui untuk mensimulasikan cookie.
        $perangkat->update(['token_hash' => hash('sha256', 'TOKEN-UJI')]);
        $this->flushSession();
        \Illuminate\Support\Facades\Auth::guard('wali')->logout();

        $this->withCookie(IngatPerangkat::COOKIE, 'TOKEN-UJI')
            ->get(route('portal.beranda'))->assertOk();

        // Staf mengganti email keluarga → perangkat lama gugur.
        $wali->update(['email_ayah' => 'baru-hasan@contoh.id']);
        $this->assertNotNull($perangkat->refresh()->dicabut_pada);
    }

    // ---- Pendaftaran ----

    public function test_pendaftar_baru_mendaftar_lalu_masuk_sebagai_wali(): void
    {
        $this->masukSebagai('aminah@contoh.id');

        // Pendaftar baru → keluarga lahir → membuat password untuk masuk berikutnya.
        $this->post(route('portal.daftar'), $this->isianDaftar())->assertRedirect(route('portal.sandi.buat'));
        $this->buatSandi()->assertRedirect(route('portal.beranda'));

        $santri = Santri::sole();
        $wali = $santri->wali;
        $this->assertSame('calon', $santri->status);
        $this->assertSame('ibu', $wali->kontak_utama);
        $this->assertSame('aminah@contoh.id', $wali->email_ibu);
        $this->assertSame('081299990001', $wali->telepon_ibu, 'nomor dinormalkan: +62 → 0, tanpa spasi & tanda hubung');
        $this->assertSame('portal', Pendaftaran::where('id_santri', $santri->id)->value('asal'));
        $this->assertSame(1, $santri->tagihan()->where('perilaku', 'registrasi')->count(), 'jalur yang sama dengan panitia');

        $ttd = PersetujuanTerm::sole();
        $this->assertSame('otp_email', $ttd->metode_ttd);
        $this->assertSame(hash('sha256', 'Patuh tata tertib.'), $ttd->hash_sha256);
        $this->assertNull($ttd->disusun_oleh);
        $this->assertSame('aminah@contoh.id', $ttd->penanda_tangan_email);

        // Langsung masuk — beranda menampilkan anaknya.
        $this->assertSame('ibu', session('portal.peran'));
        $this->get(route('portal.beranda'))->assertOk()->assertSee('AHMAD FAQIH')->assertSee('Rp 500.000');
    }

    /** Celah pengambilalihan: nomor HP keluarga lain tak boleh menempelkan pendaftar ke keluarga itu. */
    public function test_nomor_hp_yang_sudah_terdaftar_ditolak_tanpa_menyebut_pemiliknya(): void
    {
        $this->keluarga(['telepon_ayah' => '081299990001', 'telepon' => '081299990001']);
        $this->masukSebagai('penyusup@contoh.id');

        $this->post(route('portal.daftar'), $this->isianDaftar())
            ->assertSessionHas('error', fn ($p) => str_contains($p, 'sudah terdaftar') && ! str_contains($p, 'Hasan'));

        $this->assertSame(0, Santri::count());
        $this->assertSame(1, Wali::count());
    }

    /** Data keluarga selengkap modul Wali/Keluarga; pekerjaan "Lainnya" menyimpan yang diketik. */
    public function test_pendaftar_baru_mengisi_data_keluarga_lengkap(): void
    {
        $this->masukSebagai('aminah@contoh.id');

        $this->post(route('portal.daftar'), $this->isianDaftar([
            'email_ibu' => 'palsu@contoh.id', // diabaikan: email pendaftar = email OTP
            'telepon_ayah' => '0813 7777 0002', 'email_ayah' => ' Faqih@Contoh.id ',
            'pekerjaan_ayah' => 'Lainnya', 'pekerjaan_lain_ayah' => 'Penerjemah', 'pendapatan_ayah' => 'juta_5_10',
            'pekerjaan_ibu' => 'Guru / Dosen', 'nik' => '3201010101010001', 'alamat' => 'Bogor',
        ]))->assertRedirect(route('portal.sandi.buat'));

        $wali = Santri::sole()->wali;
        $this->assertSame('aminah@contoh.id', $wali->email_ibu);
        $this->assertSame('faqih@contoh.id', $wali->email_ayah);
        $this->assertSame('081377770002', $wali->telepon_ayah);
        $this->assertSame('Penerjemah', $wali->pekerjaan_ayah);
        $this->assertSame('juta_5_10', $wali->pendapatan_ayah);
        $this->assertSame('Guru / Dosen', $wali->pekerjaan_ibu);
        $this->assertSame('3201010101010001', $wali->nik);
        $this->assertNull($wali->nama_wali);

        // Ayah kini bisa masuk portal dengan emailnya sendiri, ke keluarga yang sama.
        $this->assertSame(['status' => 'satu', 'peran' => 'ayah'],
            \Illuminate\Support\Arr::only((new MasukWali)->cariKeluarga('faqih@contoh.id'), ['status', 'peran']));
    }

    public function test_nama_ayah_ibu_dan_pekerjaan_lainnya_wajib(): void
    {
        $this->masukSebagai('aminah@contoh.id');

        $this->post(route('portal.daftar'), $this->isianDaftar(['nama_ayah' => '', 'pekerjaan_ibu' => 'Lainnya']))
            ->assertSessionHasErrors(['nama_ayah', 'pekerjaan_lain_ibu']);
        $this->assertSame(0, Santri::count());
    }

    /** Email/HP orang tua kedua yang milik keluarga lain ditolak — tanpa menyebut pemiliknya. */
    public function test_email_dan_hp_peran_lain_milik_keluarga_lain_ditolak(): void
    {
        $this->keluarga(); // Bapak Hasan: hasan@contoh.id, 081211110000
        $this->masukSebagai('aminah@contoh.id');

        $this->post(route('portal.daftar'), $this->isianDaftar(['email_ayah' => 'HASAN@contoh.id']))
            ->assertSessionHas('error', fn ($p) => str_contains($p, 'Email Ayah') && ! str_contains($p, 'Hasan'));
        $this->post(route('portal.daftar'), $this->isianDaftar(['telepon_ayah' => '+62 812 1111 0000']))
            ->assertSessionHas('error', fn ($p) => str_contains($p, 'Nomor HP Ayah') && ! str_contains($p, 'Hasan'));

        $this->assertSame(0, Santri::count());
    }

    public function test_jalur_yang_tak_dibuka_untuk_portal_ditolak(): void
    {
        $this->masukSebagai('aminah@contoh.id');

        $this->post(route('portal.daftar'), $this->isianDaftar(['jalur' => '005']))->assertSessionHas('error');
        $this->assertSame(0, Santri::count());

        $this->get(route('portal.daftar'))->assertDontSee('<option value="005"', false);
    }

    public function test_surat_pernyataan_wajib_disetujui(): void
    {
        $this->masukSebagai('aminah@contoh.id');

        $this->post(route('portal.daftar'), $this->isianDaftar(['setuju' => null]))->assertSessionHasErrors('setuju');
        $this->assertSame(0, Santri::count());
    }

    public function test_tanpa_gelombang_terbuka_pendaftaran_tutup(): void
    {
        $this->buatGelombang(self::TA, 'G1', ['berlaku_sampai' => now()->subDay()->toDateString()]);
        $this->masukSebagai('aminah@contoh.id');

        $this->get(route('portal.daftar'))->assertOk()->assertSee('sedang ditutup');
        $this->post(route('portal.daftar'), $this->isianDaftar())->assertSessionHas('error');
        $this->assertSame(0, Santri::count());
    }

    public function test_wali_terdaftar_mendaftarkan_adik_ke_keluarga_sendiri(): void
    {
        $wali = $this->keluarga();
        $this->masukSebagai('hasan@contoh.id');
        $this->buatSandi();

        // Isian wali yang ikut terkirim diabaikan — keluarga diambil dari sesi, bukan dari form.
        $this->post(route('portal.daftar'), $this->isianDaftar(['nama' => 'Adik Hasan']))
            ->assertRedirect(route('portal.beranda'));

        $this->assertSame($wali->id, Santri::sole()->id_wali);
        $this->assertSame(1, Wali::count());
        $this->assertSame('ayah', PersetujuanTerm::sole()->penanda_tangan_peran);
    }
}
