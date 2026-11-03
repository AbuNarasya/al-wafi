<?php

namespace Tests\Feature;

use App\Models\AbsensiAsrama;
use App\Models\HakAksesModul;
use App\Models\Jenjang;
use App\Models\Level;
use App\Models\NotifikasiWali;
use App\Models\Perizinan;
use App\Models\Santri;
use App\Models\User;
use App\Models\Wali;
use App\Services\PortalWali\KataSandiWali;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Absensi asrama dari ISE (push, 4 Nov 2026): penerima API, pencocokan, kategori, izin, kabar, portal & layar staf. */
class AbsensiAsramaTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-uji-ise-0123456789';

    private Santri $ahmad;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        Carbon::setTestNow('2026-11-04 06:00:00');   // 13.00 WIB
        config(['services.ise.token' => self::TOKEN]);
        Jenjang::create(['kode' => 'J002', 'nama' => 'SMP', 'urutan' => 2, 'jumlah_tingkat' => 3]);
        $w = Wali::create(['kontak_utama' => 'ibu', 'nama_ibu' => 'Ibu Aminah', 'telepon_ibu' => '081234567891', 'email_ibu' => 'i@contoh.id',
            'nama' => 'Ibu Aminah', 'telepon' => '081234567891', 'status' => 'aktif']);
        $this->ahmad = Santri::create(['no_pendaftaran' => 'L-1', 'nis' => '2425010541', 'id_wali' => $w->id, 'nama' => 'Ahmad', 'jenis_kelamin' => 'L',
            'kode_jenjang' => 'J002', 'tingkat' => 1, 'status' => 'aktif']);
        (new KataSandiWali)->simpan($w, 'ibu', 'sandiUji12345', 'sandiUji12345');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function kirim(array $data, ?string $token = self::TOKEN): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($token ? ['Authorization' => "Bearer {$token}"] : [])->postJson('/api/ise/v1/absensi-asrama', ['data' => $data]);
    }

    private function absen(string $id, array $timpa = []): array
    {
        return $timpa + ['id' => $id, 'santri' => ['nis' => '2425010541', 'id_ise' => 'STD-1', 'nama' => 'AHMAD'],
            'waktu' => '2026-11-04T04:32:00+07:00', 'asrama' => 'DAMASCUS 1C', 'tipe' => 'Fajr Congregational Prayer',
            'status' => 'Setelah Adzan', 'catatan' => 'Terminal: Masjid NI', 'pencatat' => 'Ustadz Rahasia'];
    }

    public function test_token_pencocokan_kategori_idempoten_dan_hapus(): void
    {
        config(['services.ise.token' => '']);
        $this->kirim([$this->absen('a1')])->assertNotFound();
        config(['services.ise.token' => self::TOKEN]);
        $this->kirim([$this->absen('a1')], 'salah')->assertUnauthorized();
        $this->kirim([])->assertStatus(422);

        $this->kirim([
            $this->absen('a1'),
            $this->absen('a2', ['santri' => ['nis' => '999', 'nama' => 'ORANG LAIN']]),
            $this->absen('a3', ['status' => '']),
        ])->assertOk()->assertJson(['jumlah' => 3, 'disimpan' => 2, 'ditolak' => 1, 'tak_tercocok' => 1]);
        $a1 = AbsensiAsrama::where('ise_id', 'a1')->sole();
        $this->assertSame([$this->ahmad->id, 'terlambat'], [$a1->id_santri, $a1->kategori]);
        $this->assertNull(AbsensiAsrama::where('ise_id', 'a2')->value('id_santri'));

        // id_erp diutamakan; kiriman ulang menimpa (tak menggandakan); dihapus menghapus.
        $this->kirim([$this->absen('a1', ['santri' => ['id_erp' => $this->ahmad->id, 'nis' => 'salah'], 'status' => 'Sebelum Adzan'])])->assertOk();
        $this->assertSame([1, 'hadir'], [AbsensiAsrama::where('ise_id', 'a1')->count(), AbsensiAsrama::where('ise_id', 'a1')->value('kategori')]);
        $this->kirim([['id' => 'a2', 'dihapus' => true]])->assertJson(['dihapus' => 1]);
        $this->assertSame(1, AbsensiAsrama::count());
        $this->assertSame(3, DB::table('log_kiriman_ise')->count(), 'kiriman yang ditolak di pintu (404/401/422) tak dicatat');
    }

    public function test_kabar_tidak_hadir_izin_dan_tampilan_wali(): void
    {
        // Tidak hadir → kabar sekali; kiriman ulang tak mengabari lagi; data lama (> 24 jam) tak dikabarkan.
        $this->kirim([$this->absen('b1', ['status' => "Tidak Jama'ah"])])->assertOk();
        $this->kirim([$this->absen('b1', ['status' => "Tidak Jama'ah"])])->assertOk();
        $this->kirim([$this->absen('b0', ['status' => 'Alpa', 'waktu' => '2026-11-01T04:30:00+07:00'])])->assertOk();
        $kabar = NotifikasiWali::where('jenis', 'absensi_asrama')->get();
        $this->assertCount(1, $kabar);
        $this->assertSame(['akademik', 'AHMAD tercatat tidak hadir'], [$kabar[0]->kategori, $kabar[0]->judul]);
        $this->assertStringContainsString("Tidak Jama'ah", $kabar[0]->pesan);

        // Dalam izin yang disetujui: tak dikabarkan & tampil "Izin" di portal.
        Perizinan::create(['nomor' => 'IZN-2611-0001', 'id_santri' => $this->ahmad->id, 'kode_tipe' => 'sakit', 'status' => 'keluar',
            'rencana_keluar' => '2026-11-04 08:00:00', 'rencana_kembali' => '2026-11-06 08:00:00', 'keluar_pada' => '2026-11-04 07:00:00', 'id_wali' => $this->ahmad->id_wali]);
        $this->kirim([$this->absen('b2', ['status' => 'Alpa', 'tipe' => 'Dhuhr Congregational Prayer', 'waktu' => '2026-11-04T12:10:00+07:00'])])->assertOk();
        $this->assertSame(1, NotifikasiWali::where('jenis', 'absensi_asrama')->count());
        $this->kirim([$this->absen('b3', ['status' => 'Haid', 'tipe' => 'Morning Dzikir', 'waktu' => '2026-11-04T04:59:00+07:00'])])->assertOk();

        $this->post(route('portal.masuk.sandi'), ['email' => 'i@contoh.id', 'sandi' => 'sandiUji12345']);
        $this->get(route('portal.menu'))->assertOk()->assertSee(route('portal.kehadiran'), false);
        $this->get(route('portal.kehadiran', ['tanggal' => '2026-11-04']))->assertOk()
            ->assertSee('Fajr Congregational Prayer')->assertSee('Tidak hadir')->assertSee('Dhuhr Congregational Prayer')->assertSee('Izin')
            ->assertSee('Uzur')->assertDontSee('Haid')
            ->assertDontSee('Ustadz Rahasia')->assertDontSee('Terminal: Masjid NI');   // internal: tak untuk wali
        $this->post(route('portal.bahasa'), ['bahasa' => 'en']);
        $this->get(route('portal.kehadiran', ['tanggal' => '2026-11-04']))->assertSee('Dormitory attendance')->assertSee('On leave');
    }

    public function test_layar_staf_data_tak_tercocok_dan_log(): void
    {
        $this->kirim([$this->absen('c1'), $this->absen('c2', ['santri' => ['nis' => '777', 'nama' => 'SANTRI ISE']])]);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Staf', 'max_transaksi' => null]);
        $u = User::create(['username' => 'asr', 'nama' => 'Asrama', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif'])->fresh();
        $this->actingAs($u)->get(route('absensi_asrama.index'))->assertForbidden();
        HakAksesModul::create(['id_pengguna' => $u->id_pengguna, 'kode_modul' => 'absensi-asrama', 'lihat' => true, 'menu' => true]);
        \App\Support\Akses::lupakan();
        $this->actingAs($u)->get(route('absensi_asrama.index', ['tanggal' => '2026-11-04']))->assertOk()->assertSee('AHMAD')->assertSee('Ustadz Rahasia');
        $this->actingAs($u)->get(route('absensi_asrama.index', ['tab' => 'tak_tercocok']))->assertOk()->assertSee('SANTRI ISE')->assertSee('NIS 777');
        $this->actingAs($u)->get(route('absensi_asrama.index', ['tab' => 'log']))->assertOk()->assertSee('Log kiriman ISE');
    }
}
