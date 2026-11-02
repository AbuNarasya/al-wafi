<?php

namespace Tests\Feature;

use App\Models\JenisPakaianLaundry;
use App\Models\Jenjang;
use App\Models\Laundry;
use App\Models\Level;
use App\Models\NotifikasiWali;
use App\Models\Santri;
use App\Models\User;
use App\Models\Wali;
use App\Services\Modules\LaundryService;
use App\Services\PortalWali\KataSandiWali;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Laundry tahap 3 (26 Okt 2026): menu Laundry di portal wali + kabar selesai / diserahkan / kuota. */
class PortalLaundryTest extends TestCase
{
    use RefreshDatabase;

    private Santri $ahmad;

    private Santri $lain;

    private int $petugas;

    private LaundryService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Staf', 'max_transaksi' => null]);
        $this->petugas = User::create(['username' => 'lnd', 'nama' => 'Petugas', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif'])->id_pengguna;
        Jenjang::create(['kode' => 'J002', 'nama' => 'SMP', 'urutan' => 2, 'jumlah_tingkat' => 3]);
        DB::table('pengaturan_laundry')->where('id', 1)->update(['tarif_per_kg' => 7000]);
        $w = Wali::create(['kontak_utama' => 'ibu', 'nama_ibu' => 'Ibu Aminah', 'telepon_ibu' => '081234567891', 'email_ibu' => 'i@contoh.id',
            'nama_ayah' => 'Ayah', 'nama' => 'Ibu Aminah', 'telepon' => '081234567891', 'status' => 'aktif']);
        $w2 = Wali::create(['kontak_utama' => 'ibu', 'nama_ibu' => 'Ibu Lain', 'telepon_ibu' => '081234567892', 'nama' => 'Ibu Lain', 'telepon' => '081234567892', 'status' => 'aktif']);
        $buat = fn ($nis, $nama, $wali) => Santri::create(['no_pendaftaran' => "L-{$nis}", 'nis' => $nis, 'id_wali' => $wali, 'nama' => $nama,
            'jenis_kelamin' => 'L', 'kode_jenjang' => 'J002', 'tingkat' => 1, 'status' => 'aktif']);
        $this->ahmad = $buat('1001', 'Ahmad', $w->id);
        $this->lain = $buat('1002', 'Orang Lain', $w2->id);
        (new KataSandiWali)->simpan($w, 'ibu', 'sandiUji12345', 'sandiUji12345');
        $this->svc = new LaundryService;
    }

    private function setor(Santri $s, string $kg, int $potong = 4): Laundry
    {
        return $this->svc->terima($s, $kg, [['id_jenis_pakaian' => JenisPakaianLaundry::where('nama', 'Gamis')->value('id'), 'jumlah' => $potong]], null, 'kartu', $this->petugas);
    }

    private function masuk(): void
    {
        $this->post(route('portal.masuk.sandi'), ['email' => 'i@contoh.id', 'sandi' => 'sandiUji12345']);
    }

    public function test_kabar_selesai_diserahkan_dan_kuota_sekali_di_tiap_ambang(): void
    {
        $a = $this->setor($this->ahmad, '17');     // 0 → 85% : hampir habis
        $b = $this->setor($this->ahmad, '2');      // 85% → 95% : tak ada kabar baru
        $this->setor($this->ahmad, '3');           // 95% → 110% : terlampaui
        $this->setor($this->ahmad, '1');           // sudah lewat : tak ada kabar baru
        $kuota = NotifikasiWali::where('jenis', 'laundry_kuota')->orderBy('id')->pluck('judul')->all();
        $this->assertCount(2, $kuota);
        $this->assertStringContainsString('hampir habis', $kuota[0]);
        $this->assertStringContainsString('terlampaui', $kuota[1]);
        $this->assertSame('laundry', NotifikasiWali::where('jenis', 'laundry_kuota')->value('kategori'));

        $this->svc->majukan([$a->id, $b->id], 'diterima', $this->petugas);
        $this->svc->majukan([$a->id, $b->id], 'diproses', $this->petugas);
        $this->assertSame(2, NotifikasiWali::where('jenis', 'laundry_selesai')->count());

        $item = $a->item()->first();
        $this->svc->serahkan($this->ahmad, [$a->id], [$item->id => 3], 'Satu gamis dicari dulu', 'kartu', $this->petugas);
        $pesan = NotifikasiWali::where('jenis', 'laundry_diserahkan')->value('pesan');
        $this->assertStringContainsString('Gamis 1', $pesan);
        $this->assertStringContainsString('Satu gamis dicari dulu', $pesan);
    }

    public function test_halaman_laundry_portal_per_anak_dan_tiga_bahasa(): void
    {
        $a = $this->setor($this->ahmad, '22');
        $this->svc->majukan([$a->id], 'diterima', $this->petugas);
        $this->svc->majukan([$a->id], 'diproses', $this->petugas);
        $this->setor($this->lain, '5');

        $this->masuk();
        $this->get(route('portal.laundry'))->assertOk()
            ->assertSee('AHMAD')->assertSee('Siap diambil')->assertSee('Melebihi kuota 2,0 kg')->assertSee('14.000')
            ->assertDontSee('ORANG LAIN');
        // Anak keluarga lain lewat ?anak= tak bisa diintip — jatuh ke anak sendiri.
        $this->get(route('portal.laundry', ['anak' => $this->lain->id]))->assertOk()->assertSee('AHMAD')->assertDontSee('ORANG LAIN');

        $this->post(route('portal.bahasa'), ['bahasa' => 'ar']);
        $this->get(route('portal.laundry'))->assertOk()->assertSee('dir="rtl"', false)->assertSee(__('Siap diambil', [], 'ar'));
        $this->post(route('portal.bahasa'), ['bahasa' => 'en']);
        $this->get(route('portal.laundry'))->assertOk()->assertSee('Ready for pickup');

        // Menu tersedia di katalog portal.
        $this->get(route('portal.menu'))->assertOk()->assertSee(route('portal.laundry'), false);
    }
}
