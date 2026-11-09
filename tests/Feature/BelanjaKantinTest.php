<?php

namespace Tests\Feature;

use App\Http\Middleware\TandaTanganToko;
use App\Models\ActivityLog;
use App\Models\CompanySettings;
use App\Models\DompetSantri;
use App\Models\HakAksesModul;
use App\Models\Santri;
use App\Models\TransaksiDompetToko;
use App\Models\User;
use App\Models\Wali;
use App\Services\Modules\KartuSantriService;
use App\Services\PortalWali\PinTransaksi;
use App\Support\Akses;
use App\Support\BelanjaKantin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * BELANJA KARTU DI KANTIN MITRA — Paket 1 sisi ERP (9 Nov 2026): asal transaksi di penghubung,
 * batas harian gabung/pisah, saklar pilihan kantin di portal wali.
 */
class BelanjaKantinTest extends TestCase
{
    use RefreshDatabase;

    private const RAHASIA = 'rahasia-uji-kantin';

    private const UID = 'KARTU-AHMAD';

    private const PIN_WALI = '482915';

    private Santri $ahmad;

    private Wali $wali;

    private User $staf;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['penghubung.rahasia' => self::RAHASIA, 'penghubung.ip' => '']);
        CompanySettings::updateOrCreate(['id' => 1], ['nama_perusahaan' => 'Yayasan', 'jenis_perusahaan' => 'Yayasan', 'mata_uang' => 'IDR',
            'periode_awal_pembukuan' => '2026-01-01', 'bulan_awal_anggaran' => 1, 'batas_jajan_harian' => 30000]);
        \App\Models\Level::create(['kode_level' => 'L1', 'nama_level' => 'Staf', 'max_transaksi' => null]);
        \App\Models\Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'jumlah_tingkat' => 3]);
        $this->wali = Wali::create(['kontak_utama' => 'ibu', 'nama_ibu' => 'Ibu', 'telepon_ibu' => '0811', 'email_ibu' => 'ibu@contoh.id',
            'nama' => 'Ibu', 'telepon' => '0811', 'status' => 'aktif']);
        $this->ahmad = Santri::create(['no_pendaftaran' => 'ZZKB-1', 'nis' => '1001', 'nama' => 'AHMAD', 'jenis_kelamin' => 'L',
            'kode_jenjang' => 'SMP', 'jalur' => 'reguler', 'status' => 'aktif', 'id_wali' => $this->wali->id]);
        DompetSantri::create(['id_santri' => $this->ahmad->id, 'saldo' => 200000]);
        $k = new KartuSantriService;
        $k->pasang($this->ahmad->id, self::UID, null);
        $k->pasangPin($this->ahmad, '730519', '730519', $this->wali->id, 'ibu');
        (new PinTransaksi($this->wali, 'ibu'))->simpan(self::PIN_WALI);
        $this->staf = User::create(['username' => 'stafkb', 'nama' => 'Staf Kantin', 'password_hash' => 'x', 'kode_level' => 'L1',
            'is_admin' => false, 'status' => 'aktif'])->fresh();
    }

    private function kirim(string $path, array $isi): TestResponse
    {
        $waktu = (string) time();
        $badan = json_encode($isi);
        $tanda = TandaTanganToko::tanda(self::RAHASIA, 'POST', $path, $waktu, $badan);

        return $this->call('POST', $path, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PENGHUBUNG_WAKTU' => $waktu, 'HTTP_X_PENGHUBUNG_TANDA' => $tanda,
        ], $badan);
    }

    private function potong(string $nomor, int $nominal, ?string $asal = null, ?string $pin = null): TestResponse
    {
        return $this->kirim('/api/dompet-toko/v1/potong', array_filter(['nomor' => $nomor, 'uid' => self::UID, 'nominal' => $nominal,
            'pin' => $pin, 'asal' => $asal, 'kode_toko' => $asal === 'kantin' ? 'KB01' : 'L002',
            'nama_toko' => $asal === 'kantin' ? 'Kantin Basah' : 'Wafi Mart'], fn ($v) => $v !== null));
    }

    private function atur(string $mode, int $batasKantin = 15000, bool $saklar = false): void
    {
        BelanjaKantin::simpan($mode, $batasKantin, $saklar, $this->staf->id_pengguna);
    }

    private function sebagaiWali(): static
    {
        return $this->actingAs($this->wali, 'wali')->withSession(['portal.peran' => 'ibu', 'portal.punya_sandi' => true]);
    }

    public function test_klien_lama_tanpa_asal_tetap_wafi_mart_dan_asal_tersimpan(): void
    {
        $this->potong('WM-1', 10000)->assertOk();
        $this->potong('KB-1', 5000, 'kantin')->assertOk();
        $this->assertSame(['WM-1' => 'mart', 'KB-1' => 'kantin'], TransaksiDompetToko::orderBy('id')->pluck('asal', 'nomor_toko')->all());
        $this->assertSame('Belanja Kantin Basah KB-1', TransaksiDompetToko::where('nomor_toko', 'KB-1')->sole()->mutasi->keterangan);

        // Pengembalian mewarisi asal transaksi asalnya.
        $this->kirim('/api/dompet-toko/v1/kembalikan', ['nomor' => 'KB-1-R', 'nomor_asal' => 'KB-1', 'nominal' => 2000])->assertOk();
        $this->assertSame('kantin', TransaksiDompetToko::where('nomor_toko', 'KB-1-R')->value('asal'));
        $this->kirim('/api/dompet-toko/v1/potong', ['nomor' => 'X', 'uid' => self::UID, 'nominal' => 1, 'asal' => 'warung'])->assertStatus(422);
    }

    public function test_mode_gabung_satu_batas_untuk_semua_tempat(): void
    {
        $this->atur('gabung');
        $this->potong('WM-1', 20000)->assertOk();
        // 20.000 di Wafi Mart + 15.000 di kantin > batas 30.000 → PIN.
        $this->kirim('/api/dompet-toko/v1/kartu', ['uid' => self::UID, 'total' => 15000, 'asal' => 'kantin'])->assertOk()
            ->assertJson(['sisa_batas' => '10000.00', 'perlu_pin' => true]);
        $this->potong('KB-1', 15000, 'kantin')->assertStatus(409);
        $this->potong('KB-1', 15000, 'kantin', '730519')->assertOk()->assertJson(['sisa_batas' => '0.00']);
    }

    public function test_mode_pisah_batas_wafi_mart_dan_kantin_sendiri_sendiri(): void
    {
        $this->atur('pisah', 15000);
        $this->potong('WM-1', 25000)->assertOk()->assertJson(['sisa_batas' => '5000.00']);
        // Belanja Wafi Mart tak memakan jatah kantin.
        $this->kirim('/api/dompet-toko/v1/kartu', ['uid' => self::UID, 'total' => 15000, 'asal' => 'kantin'])->assertOk()
            ->assertJson(['sisa_batas' => '15000.00', 'perlu_pin' => false]);
        $this->potong('KB-1', 15000, 'kantin')->assertOk()->assertJson(['sisa_batas' => '0.00']);
        $this->potong('KB-2', 1000, 'kantin')->assertStatus(409);
        // Pengembalian kantin mengembalikan jatah KANTIN.
        $this->kirim('/api/dompet-toko/v1/kembalikan', ['nomor' => 'KB-1-R', 'nomor_asal' => 'KB-1', 'nominal' => 5000])->assertOk()
            ->assertJson(['sisa_batas' => '5000.00']);
        $this->potong('KB-2', 5000, 'kantin')->assertOk();
        // Batas Wafi Mart tetap 30.000 − 25.000.
        $this->kirim('/api/dompet-toko/v1/kartu', ['uid' => self::UID, 'total' => 6000])->assertOk()->assertJson(['sisa_batas' => '5000.00', 'perlu_pin' => true]);

        // Batas kantin milik anak mengalahkan bawaan kantin.
        (new KartuSantriService)->aturBatasKantin($this->ahmad, 50000);
        $this->assertSame('50000.00', KartuSantriService::batasHarianKantin($this->ahmad->id));
    }

    public function test_saklar_wali_menentukan_pilihan_kantin(): void
    {
        DompetSantri::where('id_santri', $this->ahmad->id)->update(['kantin_nonaktif' => true]);

        // Saklar petugas MATI: pilihan wali tak berlaku, kantin boleh.
        $this->atur('gabung', 15000, false);
        $this->potong('KB-1', 1000, 'kantin')->assertOk();

        // Saklar NYALA: kantin ditolak (sebelum PIN), Wafi Mart tetap bisa.
        $this->atur('gabung', 15000, true);
        $this->kirim('/api/dompet-toko/v1/kartu', ['uid' => self::UID, 'total' => 1000, 'asal' => 'kantin'])->assertForbidden();
        $this->potong('KB-2', 1000, 'kantin')->assertForbidden();
        $this->potong('WM-1', 1000)->assertOk();
        $this->assertNull(TransaksiDompetToko::where('nomor_toko', 'KB-2')->first());
    }

    public function test_portal_wali_saklar_kantin_dan_batas_pisah(): void
    {
        // Saklar mati + gabung: tak ada pilihan kantin, satu batas.
        $this->sebagaiWali()->get(route('portal.anak', $this->ahmad->id))->assertOk()
            ->assertSee('Batas belanja harian')->assertDontSee('Belanja di kantin')->assertDontSee('Batas harian kantin');
        $this->sebagaiWali()->post(route('portal.kartu.kantin', $this->ahmad->id), ['aktif' => 0, 'pin' => self::PIN_WALI])->assertSessionHas('error');
        $this->assertFalse((bool) DompetSantri::where('id_santri', $this->ahmad->id)->value('kantin_nonaktif'));

        $this->atur('pisah', 15000, true);
        $this->sebagaiWali()->get(route('portal.anak', $this->ahmad->id))->assertOk()
            ->assertSee('Batas harian Wafi Mart')->assertSee('Batas harian kantin')->assertSee('Rp 15.000 (bawaan)')
            ->assertSee('Matikan belanja di kantin');

        // PIN wali salah → tak berubah.
        $this->sebagaiWali()->post(route('portal.kartu.kantin', $this->ahmad->id), ['aktif' => 0, 'pin' => '000000'])->assertSessionHas('error');
        $this->assertFalse((bool) DompetSantri::where('id_santri', $this->ahmad->id)->value('kantin_nonaktif'));

        $this->sebagaiWali()->post(route('portal.kartu.kantin', $this->ahmad->id), ['aktif' => 0, 'pin' => self::PIN_WALI])
            ->assertRedirect(route('portal.anak', $this->ahmad->id))->assertSessionHas('status');
        $this->assertTrue((bool) DompetSantri::where('id_santri', $this->ahmad->id)->value('kantin_nonaktif'));
        $this->assertSame(1, ActivityLog::where('aksi', 'ubah_belanja_kantin_santri')->count());
        $this->sebagaiWali()->get(route('portal.anak', $this->ahmad->id))->assertSee('Nyalakan belanja di kantin');

        // Dua batas sekaligus.
        $this->sebagaiWali()->post(route('portal.kartu.batas', $this->ahmad->id),
            ['batas_harian' => '40000', 'batas_harian_kantin' => '12000', 'pin' => self::PIN_WALI])->assertSessionHas('status');
        $d = DompetSantri::where('id_santri', $this->ahmad->id)->first();
        $this->assertSame(['40000.00', '12000.00'], [$d->batas_harian, $d->batas_harian_kantin]);

        // Kembali gabung: isian kantin yang ikut terkirim diabaikan.
        $this->atur('gabung', 15000, true);
        $this->sebagaiWali()->post(route('portal.kartu.batas', $this->ahmad->id),
            ['batas_harian' => '35000', 'batas_harian_kantin' => '', 'pin' => self::PIN_WALI])->assertSessionHas('status');
        $this->assertSame('12000.00', DompetSantri::where('id_santri', $this->ahmad->id)->value('batas_harian_kantin'));
    }

    public function test_layar_pengaturan_butuh_hak_dan_tercatat(): void
    {
        $this->actingAs($this->staf)->get(route('belanja_kantin.edit'))->assertForbidden();

        HakAksesModul::create(['id_pengguna' => $this->staf->id_pengguna, 'kode_modul' => BelanjaKantin::MODUL,
            'lihat' => true, 'buat' => false, 'ubah' => true, 'hapus' => false, 'menu' => true]);
        Akses::lupakan();
        $this->actingAs($this->staf)->get(route('belanja_kantin.edit'))->assertOk()->assertSee('Belanja Kartu di Kantin')->assertSee('30.000')
            ->assertSee('value="gabung" x-model="mode" checked', false);

        $this->actingAs($this->staf)->put(route('belanja_kantin.update'), ['mode_batas' => 'pisah', 'batas_kantin_bawaan' => '10000', 'saklar_wali' => '1'])
            ->assertRedirect(route('belanja_kantin.edit'))->assertSessionHas('status');
        $b = DB::table('pengaturan_belanja_kantin')->sole();
        $this->assertSame(['pisah', '10000.00', true], [$b->mode_batas, $b->batas_kantin_bawaan, (bool) $b->saklar_wali]);
        $this->assertSame(1, ActivityLog::where('aksi', 'ubah_pengaturan_belanja_kantin')->count());

        $this->actingAs($this->staf)->put(route('belanja_kantin.update'), ['mode_batas' => 'acak', 'batas_kantin_bawaan' => '1'])->assertSessionHasErrors('mode_batas');
    }
}
