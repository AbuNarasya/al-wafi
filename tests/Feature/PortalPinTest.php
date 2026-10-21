<?php

namespace Tests\Feature;

use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\DompetSantri;
use App\Models\DompetWali;
use App\Models\JournalEntry;
use App\Models\Jenjang;
use App\Models\Level;
use App\Models\MutasiDompet;
use App\Models\NotifikasiWali;
use App\Models\PengajuanTarikTabungan;
use App\Models\PinWali;
use App\Models\Santri;
use App\Models\TabunganSantri;
use App\Models\User;
use App\Models\Wali;
use App\Notifications\KodeMasukWali;
use App\Services\Modules\DompetService;
use App\Services\Modules\PengajuanTabunganService;
use App\Services\PortalWali\PinTransaksi;
use App\Services\Ppsb\DompetPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * PIN transaksi dompet + pindah saldo + pengajuan tarik tabungan (keputusan 13 Okt 2026).
 */
class PortalPinTest extends TestCase
{
    use RefreshDatabase;

    private const PIN = '482915';

    private User $keuangan;

    private Wali $wali;

    private Santri $ahmad;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->keuangan = User::create(['username' => 'zzpp_keu', 'nama' => 'Petugas Keuangan', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'tim_keuangan' => true, 'status' => 'aktif']);
        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'jumlah_tingkat' => 3]);
        BusinessUnit::create(['kode_unit' => 'ZZPPU', 'nama_unit' => 'Unit']);
        CoaGroup::create(['kode_grup' => 'ZZPP', 'nama_grup' => 'PIN']);
        foreach (DompetPolicy::COA_TITIPAN as $pemilik => $kode) {
            CoaDetail::create(['kode_coa' => $kode, 'nama_coa' => "Titipan {$pemilik}", 'kode_grup' => 'ZZPP', 'jenis_saldo' => 'kredit']);
        }

        $this->wali = Wali::create(['kontak_utama' => 'ibu', 'nama_ibu' => 'Ibu Aminah', 'telepon_ibu' => '0811',
            'email_ibu' => 'aminah@contoh.id', 'nama_ayah' => 'Bapak Hasan', 'email_ayah' => 'hasan@contoh.id',
            'nama' => 'Ibu Aminah', 'telepon' => '0811', 'status' => 'aktif']);
        $this->ahmad = Santri::create(['no_pendaftaran' => 'ZZPP-1', 'nama' => 'Ahmad', 'jenis_kelamin' => 'L',
            'kode_jenjang' => 'SMP', 'jalur' => 'reguler', 'status' => 'aktif', 'id_wali' => $this->wali->id]);
    }

    private function sebagai(string $peran = 'ibu'): static
    {
        return $this->actingAs($this->wali, 'wali')->withSession(['portal.peran' => $peran, 'portal.punya_sandi' => true]);
    }

    private function pinIbu(): void
    {
        (new PinTransaksi($this->wali, 'ibu'))->simpan(self::PIN);
    }

    private function saldo(int $wali = 0, int $santri = 0, int $tabungan = 0): void
    {
        DompetWali::create(['id_wali' => $this->wali->id, 'saldo' => $wali]);
        DompetSantri::create(['id_santri' => $this->ahmad->id, 'saldo' => $santri]);
        TabunganSantri::create(['id_santri' => $this->ahmad->id, 'saldo' => $tabungan]);
    }

    // ---- PIN ----

    public function test_transaksi_tanpa_pin_diarahkan_membuat_pin(): void
    {
        $this->saldo(santri: 100000);

        $this->sebagai()->from(route('portal.dompet.pindah'))
            ->post(route('portal.dompet.pindah'), ['arah' => 'santri_wali', 'id_santri' => $this->ahmad->id, 'nominal' => 50000])
            ->assertRedirect(route('portal.pin', ['kembali' => '/wali/dompet/pindah']));
        $this->assertSame(0, MutasiDompet::count());
    }

    /** 21 Okt 2026: PIN pertama dibuat di tengah proses (pindah saldo) → sesudahnya kembali ke proses itu, bukan Beranda. */
    public function test_sesudah_pin_pertama_kembali_ke_halaman_proses(): void
    {
        $this->sebagai()->get(route('portal.pin', ['kembali' => '/wali/dompet/pindah']))->assertOk();
        $this->sebagai()->post(route('portal.pin.kode'), ['pin_baru' => self::PIN, 'pin_ulang' => self::PIN]);
        $kode = null;
        Notification::assertSentOnDemand(KodeMasukWali::class, function ($n) use (&$kode) {
            $kode = $n->kode;

            return true;
        });

        $this->post(route('portal.pin.konfirmasi'), ['kode' => $kode])->assertRedirect('/wali/dompet/pindah')->assertSessionHas('status');
    }

    public function test_alamat_kembali_di_luar_portal_diabaikan(): void
    {
        foreach (['https://jahat.example/x', '//jahat.example', '/admin', '/wali/pin/konfirmasi'] as $alamat) {
            $this->sebagai()->get(route('portal.pin', ['kembali' => $alamat]))->assertOk()
                ->assertSessionMissing(\App\Http\Controllers\PortalDompetController::PIN_KEMBALI);
        }
    }

    public function test_membuat_pin_wajib_kode_email_dan_pin_lemah_ditolak(): void
    {
        $this->sebagai()->post(route('portal.pin.kode'), ['pin_baru' => '123456', 'pin_ulang' => '123456'])->assertSessionHas('error');
        $this->sebagai()->post(route('portal.pin.kode'), ['pin_baru' => '482915', 'pin_ulang' => '482916'])->assertSessionHas('error');

        $this->sebagai()->post(route('portal.pin.kode'), ['pin_baru' => self::PIN, 'pin_ulang' => self::PIN])
            ->assertRedirect(route('portal.pin.konfirmasi'));
        $this->assertSame(0, PinWali::count(), 'belum tersimpan sebelum kode email benar');

        $kode = null;
        Notification::assertSentOnDemand(KodeMasukWali::class, function ($n, $s, $tujuan) use (&$kode) {
            $kode = $n->kode;

            return $tujuan->routes['mail'] === 'aminah@contoh.id';
        });
        // Ke beranda, bukan ke halaman asal — tombol kembali tak boleh membawa wali ke formulir PIN lagi.
        $this->post(route('portal.pin.konfirmasi'), ['kode' => $kode])
            ->assertRedirect(route('portal.beranda'))->assertSessionHas('status');

        $pin = PinWali::sole();
        $this->assertSame('ibu', $pin->peran, 'PIN per orang');
        $this->assertNotSame(self::PIN, $pin->pin_hash, 'hanya hash yang disimpan');
        $this->assertFalse((new PinTransaksi($this->wali, 'ayah'))->punya(), 'ayah punya PIN sendiri');
    }

    /** Hitungan salah dulu ikut tergulung bila galatnya dilempar di dalam transaksi — PIN tak pernah terkunci. */
    public function test_salah_lima_kali_terkunci_tiga_puluh_menit_dan_dikabarkan(): void
    {
        $this->pinIbu();
        $this->saldo(santri: 100000);
        $kirim = fn (string $pin) => $this->sebagai()->post(route('portal.dompet.pindah'),
            ['arah' => 'santri_wali', 'id_santri' => $this->ahmad->id, 'nominal' => 50000, 'pin' => $pin]);

        for ($i = 1; $i <= 5; $i++) {
            $kirim('000001')->assertSessionHas('error');
        }
        $this->assertSame(5, PinWali::sole()->gagal);
        $this->assertTrue(PinWali::sole()->terkunci_sampai->isFuture());
        $this->assertSame('pin_terkunci', NotifikasiWali::sole()->jenis);

        // PIN yang benar pun ditolak selama terkunci.
        $kirim(self::PIN)->assertSessionHas('error', fn ($p) => str_contains($p, 'terkunci'));
        $this->assertSame(0, MutasiDompet::count());

        // Lewat 30 menit: terbuka lagi.
        $this->travel(31)->minutes();
        $kirim(self::PIN)->assertSessionHas('status');
        $this->assertSame(0, PinWali::sole()->gagal);
    }

    public function test_ganti_pin_dengan_pin_lama(): void
    {
        $this->pinIbu();
        $this->sebagai()->post(route('portal.pin.ganti'), ['pin_lama' => '999990', 'pin_baru' => '271828', 'pin_ulang' => '271828'])->assertSessionHas('error');
        $this->sebagai()->post(route('portal.pin.ganti'), ['pin_lama' => self::PIN, 'pin_baru' => '271828', 'pin_ulang' => '271828'])
            ->assertRedirect(route('portal.beranda'))->assertSessionHas('status');

        (new PinTransaksi($this->wali, 'ibu'))->periksa('271828');
        $this->assertTrue(true);
    }

    // ---- Pindah saldo ----

    public function test_uang_saku_ditarik_kembali_ke_dompet_wali_berjurnal_dan_kunci_tarik_tak_menghalangi(): void
    {
        $this->pinIbu();
        $this->saldo(wali: 0, santri: 150000);
        DompetSantri::where('id_santri', $this->ahmad->id)->update(['kunci_tarik' => true]);

        $this->sebagai()->post(route('portal.dompet.pindah'), [
            'arah' => 'santri_wali', 'id_santri' => $this->ahmad->id, 'nominal' => 100000, 'pin' => self::PIN,
        ])->assertSessionHas('status');

        $this->assertEquals(50000, (float) DompetSantri::sole()->saldo);
        $this->assertEquals(100000, (float) DompetWali::sole()->saldo);

        $keluar = MutasiDompet::where('pemilik', 'santri')->sole();
        $this->assertSame($this->wali->id, $keluar->dicatat_oleh_wali);
        $jurnal = JournalEntry::with('lines')->find($keluar->journal_entry_id);
        $this->assertEquals(100000, (float) $jurnal->lines->firstWhere('kode_coa', DompetPolicy::COA_TITIPAN['santri'])->debet);
        $this->assertEquals(100000, (float) $jurnal->lines->firstWhere('kode_coa', DompetPolicy::COA_TITIPAN['wali'])->kredit);
    }

    public function test_tabungan_tak_bisa_dipindah_langsung(): void
    {
        $this->saldo(tabungan: 500000);

        $this->expectException(\App\Exceptions\AppException::class);
        (new DompetService)->pindah(['dari' => 'tabungan', 'ke' => 'wali', 'id_wali' => $this->wali->id,
            'id_santri' => $this->ahmad->id, 'nominal' => 100000, 'tanggal' => now()->toDateString()], $this->keuangan->id_pengguna);
    }

    // ---- Tarik tabungan ----

    public function test_pengajuan_tabungan_disetujui_keuangan_masuk_dompet_wali_berjurnal(): void
    {
        $this->pinIbu();
        $this->saldo(tabungan: 500000);

        $this->sebagai()->post(route('portal.dompet.tabungan'), [
            'id_santri' => $this->ahmad->id, 'nominal' => 600000, 'alasan' => 'Kursus', 'pin' => self::PIN,
        ])->assertSessionHas('error', fn ($p) => str_contains($p, 'Melebihi saldo'));

        $this->sebagai()->post(route('portal.dompet.tabungan'), [
            'id_santri' => $this->ahmad->id, 'nominal' => 300000, 'alasan' => 'Biaya kursus', 'pin' => self::PIN,
        ])->assertSessionHas('status');
        $p = PengajuanTarikTabungan::sole();
        $this->assertSame('menunggu', $p->status);
        $this->assertEquals(500000, (float) TabunganSantri::sole()->saldo, 'belum bergerak sebelum disetujui');

        (new PengajuanTabunganService)->setujui($p->id, $this->keuangan->id_pengguna);

        $this->assertSame('disetujui', $p->refresh()->status);
        $this->assertEquals(200000, (float) TabunganSantri::sole()->saldo);
        $this->assertEquals(300000, (float) DompetWali::sole()->saldo);
        $this->assertNotNull(MutasiDompet::find($p->id_mutasi)->journal_entry_id);
        $this->assertSame('Penarikan tabungan disetujui', NotifikasiWali::sole()->judul);
    }

    public function test_pengajuan_tabungan_ditolak_dan_dikabarkan(): void
    {
        $this->pinIbu();
        $this->saldo(tabungan: 500000);
        $this->sebagai()->post(route('portal.dompet.tabungan'), [
            'id_santri' => $this->ahmad->id, 'nominal' => 100000, 'alasan' => 'Jajan', 'pin' => self::PIN,
        ]);

        $this->actingAs($this->keuangan, 'web')->post(route('dompet.tabungan.tolak', PengajuanTarikTabungan::sole()->id), ['alasan' => 'Bukan keperluan mendesak'])
            ->assertSessionHas('status');

        $this->assertSame('ditolak', PengajuanTarikTabungan::sole()->status);
        $this->assertEquals(500000, (float) TabunganSantri::sole()->saldo);
        $this->sebagai()->get(route('portal.kabar'))->assertSee('Bukan keperluan mendesak');
    }

    // ---- Auto-debet ----

    public function test_mengaktifkan_auto_debet_wajib_pin_mematikan_tidak(): void
    {
        $this->pinIbu();
        $this->saldo(santri: 1);

        $this->sebagai()->post(route('portal.dompet.auto_debet'), ['aktif' => '1', 'pin' => '000001'])->assertSessionHas('error');
        $this->assertFalse($this->wali->refresh()->auto_debet);

        $this->sebagai()->post(route('portal.dompet.auto_debet'), ['aktif' => '1', 'pin' => self::PIN]);
        $this->assertTrue($this->wali->refresh()->auto_debet);

        $this->sebagai()->post(route('portal.dompet.auto_debet'), ['aktif' => '0']);
        $this->assertFalse($this->wali->refresh()->auto_debet);
    }
}
