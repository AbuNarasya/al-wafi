<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\CompanySettings;
use App\Models\DompetSantri;
use App\Models\HakAksesModul;
use App\Models\ImporBatch;
use App\Models\Jenjang;
use App\Models\KartuSantri;
use App\Models\Level;
use App\Models\PinSantri;
use App\Models\Santri;
use App\Models\User;
use App\Models\Wali;
use App\Services\Impor\ImporSaldoAwal;
use App\Services\Modules\KartuSantriService;
use App\Services\PortalWali\PinTransaksi;
use App\Support\Akses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * KARTU RFID & PENGAMAN BELANJA SANTRI (D1, 19 Okt 2026).
 */
class KartuSantriTest extends TestCase
{
    use RefreshDatabase;

    private const PIN_WALI = '482915';

    private Wali $wali;

    private Santri $ahmad;

    private Santri $budi;

    private User $staf;

    private KartuSantriService $k;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'jumlah_tingkat' => 3]);
        $this->wali = Wali::create(['kontak_utama' => 'ibu', 'nama_ibu' => 'Ibu Aminah', 'telepon_ibu' => '0811', 'email_ibu' => 'aminah@contoh.id',
            'nama' => 'Ibu Aminah', 'telepon' => '0811', 'status' => 'aktif']);
        $this->ahmad = Santri::create(['no_pendaftaran' => 'ZZKS-1', 'nis' => '242501001', 'nama' => 'Ahmad', 'jenis_kelamin' => 'L',
            'kode_jenjang' => 'SMP', 'jalur' => 'reguler', 'status' => 'aktif', 'id_wali' => $this->wali->id]);
        $waliLain = Wali::create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Bapak Umar', 'telepon_ayah' => '0812', 'nama' => 'Bapak Umar', 'telepon' => '0812', 'status' => 'aktif']);
        $this->budi = Santri::create(['no_pendaftaran' => 'ZZKS-2', 'nis' => '242501002', 'nama' => 'Budi', 'jenis_kelamin' => 'L',
            'kode_jenjang' => 'SMP', 'jalur' => 'reguler', 'status' => 'aktif', 'id_wali' => $waliLain->id]);
        $this->staf = User::create(['username' => 'stafks', 'nama' => 'Staf', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif'])->fresh();
        HakAksesModul::create(['id_pengguna' => $this->staf->id_pengguna, 'kode_modul' => 'kartu-santri', 'lihat' => true, 'buat' => true, 'ubah' => true, 'hapus' => false, 'menu' => true]);
        Akses::lupakan();
        (new PinTransaksi($this->wali, 'ibu'))->simpan(self::PIN_WALI);
        $this->k = new KartuSantriService;
    }

    private function sebagaiWali(): static
    {
        return $this->actingAs($this->wali, 'wali')->withSession(['portal.peran' => 'ibu', 'portal.punya_sandi' => true]);
    }

    // ---- Kartu ----

    public function test_pasang_ganti_dan_uid_unik(): void
    {
        $k1 = $this->k->pasang($this->ahmad->id, ' 00 12-34:56:78 ', $this->staf->id_pengguna);
        $this->assertSame(['0012345678', 'aktif'], [$k1->uid, $k1->status]);

        // UID milik orang lain ditolak.
        try {
            $this->k->pasang($this->budi->id, '0012345678', $this->staf->id_pengguna);
            $this->fail('UID kembar seharusnya ditolak.');
        } catch (AppException $e) {
            $this->assertStringContainsString('atas nama AHMAD', $e->getMessage());   // nama santri disimpan kapital
        }

        // Ganti kartu → kartu lama otomatis terblokir; tetap satu yang aktif.
        $this->k->pasang($this->ahmad->id, 'ABCD9999', $this->staf->id_pengguna);
        $this->assertSame(['diblokir', 'Diganti kartu baru'], [$k1->fresh()->status, $k1->fresh()->alasan_blokir]);
        $this->assertSame('ABCD9999', $this->ahmad->kartuAktif()->value('uid'));
        $this->assertNull($this->k->cariAktif('0012345678'));
        $this->assertSame('AHMAD', $this->k->cariAktif('abcd 9999')->santri->nama);

        // Membuka blokir kartu lama ditolak selama kartu baru aktif.
        $this->expectException(AppException::class);
        $this->k->bukaBlokir($k1->fresh());
    }

    public function test_batas_harian_bawaan_dan_milik_anak(): void
    {
        $this->assertSame('20000.00', KartuSantriService::batasHarian($this->ahmad->id));   // bawaan migrasi

        CompanySettings::create(['id' => 1, 'nama_perusahaan' => 'Uji', 'periode_awal_pembukuan' => '2026-01-01', 'batas_jajan_harian' => 25000]);
        $this->assertSame('25000.00', KartuSantriService::batasHarian($this->ahmad->id));

        $this->k->aturBatas($this->ahmad, 15000);
        $this->assertSame('15000.00', KartuSantriService::batasHarian($this->ahmad->id));
        $this->k->aturBatas($this->ahmad, null);
        $this->assertSame('25000.00', KartuSantriService::batasHarian($this->ahmad->id));
    }

    public function test_pin_belanja_santri_terkunci_sesudah_lima_salah(): void
    {
        try {
            $this->k->periksaPin($this->ahmad->id, '111111');
            $this->fail('Tanpa PIN terpasang seharusnya ditolak.');
        } catch (AppException $e) {
            $this->assertSame(428, $e->status);
        }

        $this->k->pasangPin($this->ahmad, '730519', '730519', $this->wali->id, 'ibu');
        $this->k->periksaPin($this->ahmad->id, '730519');   // benar → tak melempar

        for ($i = 0; $i < 5; $i++) {
            try {
                $this->k->periksaPin($this->ahmad->id, '000001');
            } catch (AppException) {
            }
        }
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/terkunci/');
        $this->k->periksaPin($this->ahmad->id, '730519');
    }

    // ---- Portal wali ----

    public function test_wali_mengatur_batas_pin_anak_dan_blokir_dengan_pin_wali(): void
    {
        $this->k->pasang($this->ahmad->id, '0012345678', $this->staf->id_pengguna);
        $this->sebagaiWali()->get(route('portal.anak', $this->ahmad->id))->assertOk()
            ->assertSee('Kartu belanja Wafi Mart')->assertSee('…5678')->assertSee('Rp 20.000 (bawaan)');

        // PIN wali salah → tak ada yang berubah, dan PIN anak tak ikut tersimpan di isian lama.
        $this->sebagaiWali()->from(route('portal.anak', $this->ahmad->id))->post(route('portal.kartu.pin', $this->ahmad->id),
            ['pin_anak' => '730519', 'pin_anak_ulang' => '730519', 'pin' => '000000'])
            ->assertRedirect()->assertSessionHas('error')->assertSessionMissing('_old_input.pin_anak');
        $this->assertFalse(KartuSantriService::punyaPin($this->ahmad->id));

        $this->sebagaiWali()->post(route('portal.kartu.pin', $this->ahmad->id), ['pin_anak' => '730519', 'pin_anak_ulang' => '730519', 'pin' => self::PIN_WALI])
            ->assertRedirect(route('portal.anak', $this->ahmad->id))->assertSessionHas('status');
        $this->assertTrue(Hash::check('730519', PinSantri::find($this->ahmad->id)->pin_hash));

        $this->sebagaiWali()->post(route('portal.kartu.batas', $this->ahmad->id), ['batas_harian' => '15000', 'pin' => self::PIN_WALI])->assertSessionHas('status');
        $this->assertSame('15000.00', DompetSantri::where('id_santri', $this->ahmad->id)->value('batas_harian'));

        $this->sebagaiWali()->post(route('portal.kartu.blokir', $this->ahmad->id), ['pin' => self::PIN_WALI])->assertSessionHas('status');
        $k = KartuSantri::sole();
        $this->assertSame(['diblokir', $this->wali->id], [$k->status, $k->diblokir_oleh_wali]);
    }

    public function test_wali_tak_bisa_menyentuh_anak_keluarga_lain(): void
    {
        $this->sebagaiWali()->post(route('portal.kartu.batas', $this->budi->id), ['batas_harian' => '1', 'pin' => self::PIN_WALI])->assertNotFound();
    }

    // ---- Staf & impor ----

    public function test_layar_staf_pasang_blokir_buka(): void
    {
        $this->actingAs($this->staf)->get(route('kartu_santri.index'))->assertOk()->assertSee('AHMAD')->assertSee('0 dari 2 santri aktif sudah berkartu', false);
        $this->actingAs($this->staf)->post(route('kartu_santri.pasang'), ['id_santri' => $this->ahmad->id, 'uid' => '0012345678'])->assertSessionHas('status');
        $k = KartuSantri::sole();

        $this->actingAs($this->staf)->post(route('kartu_santri.blokir', $k->id), ['alasan' => 'hilang'])->assertSessionHas('status');
        $this->actingAs($this->staf)->get(route('kartu_santri.index', ['tab' => 'diblokir']))->assertOk()->assertSee('hilang')->assertSee('Buka blokir');
        $this->actingAs($this->staf)->post(route('kartu_santri.buka', $k->id))->assertSessionHas('status');
        $this->assertSame('aktif', $k->fresh()->status);
    }

    public function test_impor_uid_dari_sistem_absensi_dan_batal(): void
    {
        $this->actingAs($this->staf);
        $path = tempnam(sys_get_temp_dir(), 'kartu').'.csv';
        file_put_contents($path, "nis,uid\n242501001,0012345678\n242501002,0012345678\n999,0099\n");

        $p = (new ImporSaldoAwal)->pratinjau('kartu-santri', $path, []);
        $this->assertSame([1, 2], [$p['siap'], $p['masalah']]);   // UID kembar di berkas & NIS tak dikenal

        file_put_contents($path, "nis,uid\n242501001,0012345678\n242501002,0099887766\n");
        $hasil = (new ImporSaldoAwal)->jalankan('kartu-santri', $path, [], $this->staf->id_pengguna);
        $this->assertSame(['kartu' => 2], $hasil['tersimpan']);
        $this->assertSame(0, (new ImporSaldoAwal)->pratinjau('kartu-santri', $path, [])['siap']);   // diulang → dilewati

        (new ImporSaldoAwal)->batalkanBatch($hasil['id_batch'], 'berkas keliru', $this->staf->id_pengguna);
        $this->assertSame(0, KartuSantri::count());
        $this->assertNotNull(ImporBatch::find($hasil['id_batch'])->dibatalkan_pada);
    }
}
