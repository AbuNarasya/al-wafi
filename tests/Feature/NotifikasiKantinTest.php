<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\CompanySettings;
use App\Models\HakAksesModul;
use App\Models\Level;
use App\Models\Mitra;
use App\Models\Notification;
use App\Models\PenarikanMitra;
use App\Models\PenerimaanAlWafi;
use App\Models\PenjualanMitra;
use App\Models\RekonsiliasiDompetToko;
use App\Models\User;
use App\Services\Modules\KabarKantin;
use App\Services\Modules\NotificationService;
use App\Services\Modules\SaldoMitraService;
use App\Services\Penghubung\KlienDompetToko;
use App\Support\Akses;
use App\Support\ModulRegistry;
use App\Support\TugasSaya;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * NOTIFIKASI ALUR UANG KANTIN & PENCAIRAN (10 Nov 2026): A penarikan mitra diajukan, B pencairan Al Wafi belum
 * diterima, C kabar ke mitra, D rekonsiliasi perlu diputus, E belanja kartu terlambat dicairkan (ERP Al Wafi);
 * plus menu lama "Pencairan Belanja Kartu" yang dihapus.
 */
class NotifikasiKantinTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://erp.contoh.test';

    private Mitra $mitra;

    private User $petugas;

    private User $lain;

    private User $akunMitra;

    protected function setUp(): void
    {
        parent::setUp();
        config(['instans.kode' => 'wafimart', 'penghubung.url' => self::URL, 'penghubung.rahasia' => str_repeat('k', 40)]);
        Akses::lupakan();
        CoaGroup::create(['kode_grup' => 'ZZNK', 'nama_grup' => 'Notif']);
        foreach ([['1.ZZNK.PIU', 'debet'], ['2.ZZNK.HTM', 'kredit'], ['1.ZZNK.BNK', 'debet']] as [$kd, $s]) {
            CoaDetail::create(['kode_coa' => $kd, 'nama_coa' => $kd, 'kode_grup' => 'ZZNK', 'jenis_saldo' => $s]);
        }
        CompanySettings::updateOrCreate(['id' => 1], ['id' => 1, 'nama_perusahaan' => 'Wafi Mart', 'periode_awal_pembukuan' => '2026-01-01',
            'kode_coa_kartu_santri' => '1.ZZNK.PIU', 'kode_coa_hutang_mitra' => '2.ZZNK.HTM']);
        BankAccount::create(['kode_coa' => '1.ZZNK.BNK', 'nama_rekening' => 'BSI', 'jenis_rekening' => 'bank', 'status' => 'aktif']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Staf', 'max_transaksi' => null]);

        $this->mitra = Mitra::create(['kode' => 'KB01', 'nama' => 'Kantin Basah', 'status' => 'aktif', 'bank_nama' => 'BSI', 'bank_rekening' => '7123', 'bank_atas_nama' => 'Ani']);
        $this->petugas = $this->pengguna('petugas', ['mitra-kantin' => 'ubah', 'penerimaan-al-wafi' => 'buat', 'rekonsiliasi-kartu' => 'ubah']);
        $this->lain = $this->pengguna('lain', ['mitra-kantin' => 'lihat']);
        $this->akunMitra = User::create(['username' => 'kantin', 'nama' => 'Kantin Basah', 'password_hash' => 'x', 'kode_level' => 'L1',
            'is_admin' => false, 'status' => 'aktif', 'id_mitra' => $this->mitra->id])->fresh();
    }

    private function pengguna(string $u, array $hak): User
    {
        $user = User::create(['username' => $u, 'nama' => ucfirst($u), 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif'])->fresh();
        foreach ($hak as $modul => $aksi) {
            HakAksesModul::create(['id_pengguna' => $user->id_pengguna, 'kode_modul' => $modul, 'lihat' => true,
                'buat' => $aksi === 'buat', 'ubah' => $aksi === 'ubah', 'hapus' => false, 'menu' => true]);
        }

        return $user;
    }

    /** Angka Tugas Saya sebuah menu untuk pengguna itu. */
    private function tugas(User $u, string $url): int
    {
        Auth::login($u);
        Akses::lupakan();
        TugasSaya::lupakan();

        return TugasSaya::semua()[$url]['jumlah'] ?? 0;
    }

    public function test_a_c_penarikan_mitra_dikabarkan_ke_petugas_lalu_hasilnya_ke_mitra(): void
    {
        PenjualanMitra::create(['nomor' => 'KM-1', 'id_mitra' => $this->mitra->id, 'id_kasir' => $this->akunMitra->id_pengguna,
            'waktu' => now()->subDays(2), 'total' => 50000, 'uid_kartu' => 'X1234', 'status' => 'berhasil']);
        $s = app(SaldoMitraService::class);
        $p = $s->ajukan($this->mitra, 30000, null, $this->akunMitra->id_pengguna);

        // A: hanya pemegang hak Ubah (bukan sekadar Lihat, bukan akun mitra) — lonceng + push (jenis tugas).
        $this->assertSame([$this->petugas->id_pengguna], Notification::where('jenis', KabarKantin::TUGAS_PENARIKAN)->pluck('id_pengguna')->all());
        $this->assertContains(KabarKantin::TUGAS_PENARIKAN, NotificationService::JENIS_TUGAS);
        $this->assertSame(1, $this->tugas($this->petugas, '/mitra-kantin'));
        $this->assertSame(0, $this->tugas($this->lain, '/mitra-kantin'));
        $this->assertSame('/mitra-kantin/penarikan', (new NotificationService)->tautan(Notification::where('jenis', KabarKantin::TUGAS_PENARIKAN)->sole()));

        // Diproses → tugas reda sendiri; C: mitra dikabari, titik di Saldo, kotak kabar sekali lalu dibaca.
        $s->tolak($p->id, 'Rekening salah', $this->petugas->id_pengguna);
        $this->assertSame(0, $this->tugas($this->petugas, '/mitra-kantin'));
        $this->assertSame(1, (new NotificationService)->rapikan($this->petugas->id_pengguna));
        $this->assertSame(1, KabarKantin::kabarMitraBelumDibaca($this->akunMitra->id_pengguna));
        $this->actingAs($this->akunMitra)->get(route('mitra.kasir'))->assertSee('Ada kabar baru', false);
        $this->actingAs($this->akunMitra)->get(route('mitra.saldo'))->assertOk()->assertSee('Penarikan ditolak')->assertSee('Rekening salah');
        $this->assertSame(0, KabarKantin::kabarMitraBelumDibaca($this->akunMitra->id_pengguna));
        $this->actingAs($this->akunMitra)->get(route('mitra.kasir'))->assertDontSee('Ada kabar baru', false);
    }

    public function test_b_pencairan_al_wafi_dicek_berkala_dan_dikabarkan_sekali(): void
    {
        Http::fake([self::URL.'/*' => Http::response(['pencairan' => [
            ['nomor' => 'PWM-1', 'total' => '250000.00', 'status' => 'aktif', 'sampai_tanggal' => '2026-11-08'],
            ['nomor' => 'PWM-0', 'total' => '10000.00', 'status' => 'batal', 'sampai_tanggal' => '2026-11-01'],
        ]])]);
        $k = app(KabarKantin::class);
        $this->assertSame(['menunggu' => 1, 'dikabarkan' => 1], $k->cekPencairan(app(KlienDompetToko::class)));
        $this->assertSame(['menunggu' => 1, 'dikabarkan' => 0], $k->cekPencairan(app(KlienDompetToko::class)));   // tak dobel
        $this->assertSame([$this->petugas->id_pengguna], Notification::where('jenis', KabarKantin::TUGAS_PENCAIRAN)->pluck('id_pengguna')->all());
        $this->assertSame(1, $this->tugas($this->petugas, '/penerimaan-al-wafi'));

        // Dicatat diterima → reda tanpa menunggu pemeriksaan berikutnya.
        PenerimaanAlWafi::create(['nomor_pencairan' => 'PWM-1', 'sampai_tanggal' => '2026-11-08', 'tanggal' => now()->toDateString(),
            'total' => 250000, 'kode_coa_bank' => '1.ZZNK.BNK', 'diterima_oleh' => $this->petugas->id_pengguna]);
        $this->assertSame(0, $this->tugas($this->petugas, '/penerimaan-al-wafi'));
        $this->assertSame([], KabarKantin::pencairanMenunggu());

        // ERP Al Wafi tak terjangkau: perintahnya tak gagal.
        Cache::forget(KabarKantin::CACHE_PENCAIRAN);
        $this->artisan('toko:cek-pencairan')->assertSuccessful();
    }

    public function test_d_rekonsiliasi_perlu_diputus_masuk_tugas_saya(): void
    {
        RekonsiliasiDompetToko::create(['tanggal' => now()->subDay()->toDateString(), 'status' => 'perlu_diputus', 'jumlah_transaksi' => 1,
            'bersih_al_wafi' => 0, 'jumlah_yatim' => 0, 'nilai_yatim' => 0, 'perlu_diputus' => 1, 'rincian' => [], 'dijalankan_pada' => now()]);
        $this->assertSame(1, $this->tugas($this->petugas, '/rekonsiliasi-kartu'));
        RekonsiliasiDompetToko::query()->update(['ditangani_pada' => now(), 'ditangani_oleh' => $this->petugas->id_pengguna]);
        $this->assertSame(0, $this->tugas($this->petugas, '/rekonsiliasi-kartu'));
    }

    public function test_e_belanja_kartu_terlambat_dicairkan_di_erp_al_wafi(): void
    {
        config(['instans.kode' => 'alwafi']);
        $keu = $this->pengguna('keu', ['pencairan-toko' => 'buat']);
        \App\Models\Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'jumlah_tingkat' => 3]);
        $wali = \App\Models\Wali::create(['kontak_utama' => 'ibu', 'nama_ibu' => 'Ibu', 'telepon_ibu' => '0811', 'nama' => 'Ibu', 'telepon' => '0811', 'status' => 'aktif']);
        $santri = \App\Models\Santri::create(['no_pendaftaran' => 'ZZNK-1', 'nis' => '1', 'nama' => 'A', 'jenis_kelamin' => 'L',
            'kode_jenjang' => 'SMP', 'jalur' => 'reguler', 'status' => 'aktif', 'id_wali' => $wali->id]);
        $buat = fn ($nomor, $hari, $nominal) => \App\Models\TransaksiDompetToko::create(['nomor_toko' => $nomor, 'jenis' => 'potong', 'id_santri' => $santri->id,
            'nominal' => $nominal, 'status' => 'berhasil', 'tanggal' => now()->subDays($hari)->toDateString(), 'dijurnal_pada' => now()]);
        $buat('WM-1', 3, 20000);
        $this->assertSame(0, $this->tugas($keu, '/pencairan-toko'));   // belum lewat 7 hari
        $buat('WM-2', 9, 15000);
        Auth::login($keu);
        Akses::lupakan();
        TugasSaya::lupakan();
        $t = TugasSaya::semua()['/pencairan-toko'];
        $this->assertSame(1, $t['jumlah']);
        $this->assertStringContainsString('Rp 15.000', $t['label']);

        // Batas bisa diatur dari layar Pencairan ke Wafi Mart; 0 = tak diingatkan.
        $this->actingAs($keu)->get(route('pencairan_toko.index'))->assertOk()->assertSee('lebih dari 7 hari');
        $this->actingAs($keu)->put(route('pencairan_toko.pengingat'), ['batas_hari_pencairan_toko' => 0])->assertSessionHas('status');
        $this->assertSame(0, $this->tugas($keu, '/pencairan-toko'));
        $this->actingAs($keu)->put(route('pencairan_toko.pengingat'), ['batas_hari_pencairan_toko' => 2])->assertSessionHas('status');
        $this->assertSame(1, $this->tugas($keu, '/pencairan-toko'));
        // Notifikasi Wafi Mart tak muncul di instans Al Wafi.
        $this->assertSame(0, $this->tugas($keu, '/mitra-kantin'));
    }

    public function test_menu_pencairan_belanja_kartu_lama_sudah_dihapus(): void
    {
        config(['instans.kode' => 'alwafi']);
        $admin = User::create(['username' => 'adm', 'nama' => 'Adm', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif'])->fresh();
        $this->actingAs($admin)->get('/pencairan-kartu')->assertNotFound();
        $this->assertNotContains('pencairan-kartu', array_column(ModulRegistry::MODUL, 'kode'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('pencairan_kartu'));
    }
}
