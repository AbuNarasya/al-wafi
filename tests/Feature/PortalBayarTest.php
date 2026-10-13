<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\DompetWali;
use App\Models\JenisBiaya;
use App\Models\Jenjang;
use App\Models\Level;
use App\Models\MutasiDompet;
use App\Models\NotifikasiWali;
use App\Models\PembayaranSantri;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\TahunAjaran;
use App\Models\TipeBiaya;
use App\Models\User;
use App\Models\Wali;
use App\Services\Modules\PembayaranSantriService;
use App\Services\Ppsb\DompetPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pembayaran dari Portal Wali — tiga alur:
 *  A. santri aktif bayar dari Dompet Wali (seketika, berjurnal);
 *  B. isi dompet lewat transfer (menunggu verifikasi);
 *  C. calon bayar tagihan PSB lewat transfer (menunggu verifikasi).
 */
class PortalBayarTest extends TestCase
{
    use RefreshDatabase;

    private const TA = '2026/2027';

    private const GRP = 'ZZPB';

    private const BANK = '1.ZZPB.BANK';

    protected const PIN = '482915';

    protected User $keuangan;

    protected Wali $wali;

    protected Santri $aktif;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        TipeBiaya::lupakan();

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->keuangan = User::create(['username' => 'zzpb_keu', 'nama' => 'Petugas Keuangan', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'tim_keuangan' => true, 'status' => 'aktif']);

        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'jumlah_tingkat' => 3]);
        TahunAjaran::create(['kode' => self::TA, 'nama' => 'TA Uji']);
        BusinessUnit::create(['kode_unit' => 'ZZPBU', 'nama_unit' => 'Unit']);
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Portal Bayar']);
        foreach ([['4.ZZPB.1', 'Pendapatan', 'kredit'], ['1.ZZPB.2', 'Piutang', 'debet'], [self::BANK, 'Bank', 'debet'],
            [DompetPolicy::COA_TITIPAN['wali'], 'Titipan Wali', 'kredit']] as [$k, $n, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => self::GRP, 'jenis_saldo' => $s]);
        }
        BankAccount::create(['kode_coa' => self::BANK, 'nama_rekening' => 'BSI Penerimaan', 'jenis_rekening' => 'bank',
            'nama_bank' => 'BSI', 'no_rekening' => '7123456789', 'status' => 'aktif', 'tampil_di_portal' => true]);

        foreach ([['spp', 'SPP', 1], ['daftar_ulang', 'Daftar Ulang', 2], ['registrasi', 'Registrasi', 3]] as [$kode, $nama, $urut]) {
            TipeBiaya::firstOrCreate(['kode' => $kode], ['nama' => $nama, 'perilaku' => $kode, 'urutan' => $urut, 'bawaan' => true, 'status' => 'aktif']);
        }
        foreach ([['SPP', 'SPP', 'spp'], ['DU', 'Daftar Ulang', 'daftar_ulang'], ['REG', 'Registrasi', 'registrasi']] as [$kode, $nama, $tipe]) {
            JenisBiaya::create(['kode' => $kode, 'nama' => $nama, 'tipe' => $tipe, 'kode_coa_pendapatan' => '4.ZZPB.1',
                'kode_coa_piutang' => '1.ZZPB.2', 'kode_unit' => 'ZZPBU', 'status' => 'aktif']);
        }

        $this->wali = Wali::create(['kontak_utama' => 'ibu', 'nama_ibu' => 'Ibu Aminah', 'telepon_ibu' => '0811',
            'email_ibu' => 'aminah@contoh.id', 'nama' => 'Ibu Aminah', 'telepon' => '0811', 'status' => 'aktif']);
        $this->aktif = $this->santri('Ahmad', 'aktif');
        (new \App\Services\PortalWali\PinTransaksi($this->wali, 'ibu'))->simpan(self::PIN);
    }

    private function santri(string $nama, string $status, ?Wali $wali = null): Santri
    {
        static $n = 0;

        return Santri::create(['no_pendaftaran' => 'ZZPB-'.(++$n), 'nama' => $nama, 'jenis_kelamin' => 'L',
            'kode_jenjang' => 'SMP', 'tingkat' => 1, 'tahun_ajaran' => self::TA, 'jalur' => 'reguler',
            'status' => $status, 'id_wali' => ($wali ?? $this->wali)->id]);
    }

    protected function tagihan(Santri $s, string $jenis, string $perilaku, int $nominal): TagihanSantri
    {
        static $n = 0;

        return TagihanSantri::create(['id_santri' => $s->id, 'kode_jenis' => $jenis, 'perilaku' => $perilaku,
            'kode_jenjang' => 'SMP', 'tahun_ajaran' => self::TA, 'periode' => 'P'.(++$n),
            'nominal' => $nominal, 'sisa' => $nominal, 'status' => 'belum_bayar']);
    }

    private function saldo(int $saldo): DompetWali
    {
        return DompetWali::create(['id_wali' => $this->wali->id, 'saldo' => $saldo]);
    }

    protected function sebagaiIbu(): static
    {
        return $this->actingAs($this->wali, 'wali')->withSession(['portal.peran' => 'ibu']);
    }

    private function bukti(): UploadedFile
    {
        return UploadedFile::fake()->image('bukti.jpg', 600, 800)->size(200);
    }

    // ---- A. Dari dompet ----

    public function test_santri_aktif_bayar_dari_dompet_seketika_dan_tercatat_atas_nama_wali(): void
    {
        $this->saldo(1000000);
        $du = $this->tagihan($this->aktif, 'DU', 'daftar_ulang', 600000);

        $this->sebagaiIbu()->post(route('portal.bayar.dompet', $du->id), ['nominal' => 250000, 'pin' => self::PIN])
            ->assertRedirect()->assertSessionHas('status');

        $p = PembayaranSantri::sole();
        $this->assertSame('terverifikasi', $p->status, 'dompet sudah diverifikasi saat top-up');
        $this->assertNull($p->dicatat_oleh);
        $this->assertSame($this->wali->id, $p->dicatat_oleh_wali);
        $this->assertSame('ibu', $p->dicatat_oleh_peran);
        $this->assertNotNull($p->journal_entry_id);
        $this->assertSame('Ibu Aminah (Ibu) · via portal', $p->namaPencatat());

        $this->assertEquals(750000, (float) DompetWali::sole()->saldo);
        $this->assertEquals(350000, (float) $du->refresh()->sisa, 'non-SPP boleh dicicil');
        $this->assertSame($this->wali->id, MutasiDompet::where('jenis', 'bayar_tagihan')->sole()->dicatat_oleh_wali);
    }

    /**
     * Jejak audit dulu membaca guard BAWAAN: id wali tercatat sebagai id staf —
     * insert-nya gagal karena FK, galatnya ditelan, dan PostgreSQL membatalkan
     * seluruh pembayaran. Staf yang membuka portal di browser yang sama juga
     * akan tercatat sebagai pelaku tindakan wali.
     */
    public function test_jejak_audit_transaksi_portal_tak_atas_nama_staf(): void
    {
        $this->saldo(1000000);
        $du = $this->tagihan($this->aktif, 'DU', 'daftar_ulang', 600000);

        // Staf masuk di browser yang sama DAN wali masuk portal.
        $this->actingAs($this->keuangan, 'web');
        $this->sebagaiIbu()->post(route('portal.bayar.dompet', $du->id), ['nominal' => 600000, 'pin' => self::PIN])->assertSessionHas('status');

        $this->assertSame(1, PembayaranSantri::count());
        $this->assertSame(0, \App\Models\ActivityLog::where('id_pengguna', $this->keuangan->id_pengguna)->count());
    }

    /**
     * Sesudah membayar, tombol kembali dulu (history.back) membuka lagi halaman
     * Bayar beserta papan PIN-nya. Kini: tombol kembali ke induk yang tetap,
     * halaman portal tak di-cache, dan Bayar untuk tagihan lunas dialihkan.
     */
    public function test_sesudah_lunas_halaman_bayar_tak_bisa_dibuka_lagi(): void
    {
        $this->saldo(1000000);
        $du = $this->tagihan($this->aktif, 'DU', 'daftar_ulang', 600000);

        $halaman = $this->sebagaiIbu()->get(route('portal.bayar', $du->id))->assertOk();
        $this->assertStringContainsString('no-store', $halaman->headers->get('Cache-Control'));
        $halaman->assertSee('href="'.route('portal.tagihan', ['anak' => $this->aktif->id]).'"', false);
        $this->assertStringNotContainsString('history.back', $halaman->getContent());

        $this->sebagaiIbu()->post(route('portal.bayar.dompet', $du->id), ['nominal' => 600000, 'pin' => self::PIN]);

        $this->sebagaiIbu()->get(route('portal.bayar', $du->id))
            ->assertRedirect(route('portal.tagihan', ['anak' => $this->aktif->id]))
            ->assertSessionHas('status', 'Tagihan ini sudah lunas.');
    }

    public function test_spp_dari_dompet_wajib_lunas_sekaligus(): void
    {
        $this->saldo(1000000);
        $spp = $this->tagihan($this->aktif, 'SPP', 'spp', 400000);

        $this->sebagaiIbu()->post(route('portal.bayar.dompet', $spp->id), ['nominal' => 100000, 'pin' => self::PIN])->assertSessionHas('error');
        $this->assertSame(0, PembayaranSantri::count());

        $this->sebagaiIbu()->post(route('portal.bayar.dompet', $spp->id), ['nominal' => 400000, 'pin' => self::PIN])->assertSessionHas('status');
        $this->assertSame('lunas', $spp->refresh()->status);
    }

    public function test_saldo_kurang_ditolak_tanpa_mengubah_apa_pun(): void
    {
        $this->saldo(100000);
        $du = $this->tagihan($this->aktif, 'DU', 'daftar_ulang', 600000);

        $this->sebagaiIbu()->post(route('portal.bayar.dompet', $du->id), ['nominal' => 200000, 'pin' => self::PIN])->assertSessionHas('error');

        $this->assertSame(0, PembayaranSantri::count());
        $this->assertEquals(100000, (float) DompetWali::sole()->saldo);
        $this->assertEquals(600000, (float) $du->refresh()->sisa);
    }

    public function test_tagihan_keluarga_lain_tak_bisa_dibayar(): void
    {
        $this->saldo(1000000);
        $lain = Wali::create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Lain', 'telepon_ayah' => '0822', 'nama' => 'Lain', 'telepon' => '0822', 'status' => 'aktif']);
        $tagihanLain = $this->tagihan($this->santri('Anak Lain', 'aktif', $lain), 'DU', 'daftar_ulang', 500000);

        $this->sebagaiIbu()->post(route('portal.bayar.dompet', $tagihanLain->id), ['nominal' => 500000])->assertSessionHas('error');
        $this->sebagaiIbu()->get(route('portal.bayar', $tagihanLain->id))->assertRedirect(route('portal.tagihan'));

        $this->assertSame(0, PembayaranSantri::count());
        $this->assertEquals(1000000, (float) DompetWali::sole()->saldo);
    }

    // ---- C. Calon lewat transfer ----

    public function test_calon_bayar_registrasi_lewat_transfer_lalu_penolakan_sampai_ke_wali(): void
    {
        $calon = $this->santri('Calon', 'calon');
        $reg = $this->tagihan($calon, 'REG', 'registrasi', 500000);


        $this->sebagaiIbu()->post(route('portal.bayar.transfer', $reg->id), [
            'kode_rekening' => self::BANK, 'nominal' => 500000, 'tanggal' => now()->toDateString(), 'bukti' => $this->bukti(),
        ])->assertSessionHas('status');

        $p = PembayaranSantri::sole();
        $this->assertSame('menunggu_verifikasi', $p->status);
        $this->assertSame($this->wali->id, $p->dicatat_oleh_wali);
        Storage::disk('local')->assertExists($p->bukti_path);
        $this->assertEquals(500000, (float) $reg->refresh()->sisa, 'belum diakui sebelum diverifikasi');

        // Keuangan menolak → kabarnya ke wali, bukan ke notifikasi staf (yang dulu gagal karena id kosong).
        (new PembayaranSantriService)->tolak($p->id, 'Nominal di bukti tidak sama', $this->keuangan->id_pengguna);
        $kabar = NotifikasiWali::sole();
        $this->assertSame('ibu', $kabar->peran);
        $this->sebagaiIbu()->get(route('portal.kabar'))->assertOk()->assertSee('Nominal di bukti tidak sama');
    }

    /**
     * Calon dari keluarga yang SUDAH punya anak aktif boleh bayar tagihan PSB dari
     * Dompet Wali — dan registrasi yang lunas MEMAJUKAN tahapnya, sama seperti loket.
     * Dulu jalur portal memanggil BayarDompet langsung dan tahapnya macet di "Calon".
     */
    public function test_calon_adik_bayar_registrasi_dari_dompet_tahapnya_maju(): void
    {
        $this->saldo(1000000);
        $adik = $this->santri('Adik', 'calon');
        $reg = $this->tagihan($adik, 'REG', 'registrasi', 500000);

        $this->sebagaiIbu()->get(route('portal.bayar', $reg->id))->assertOk()
            ->assertSee("cara = 'dompet'", false)->assertSee('Transfer + bukti');

        $this->sebagaiIbu()->post(route('portal.bayar.dompet', $reg->id), ['nominal' => 500000, 'pin' => self::PIN])
            ->assertSessionHas('status');

        $this->assertSame('terbayar', $adik->refresh()->status, 'gerbang tahap yang sama dengan loket');
        $p = PembayaranSantri::sole();
        $this->assertSame('terverifikasi', $p->status);
        $this->assertSame($this->wali->id, $p->dicatat_oleh_wali);
        $this->assertEquals(500000, (float) DompetWali::sole()->saldo);
    }

    public function test_calon_keluarga_baru_tanpa_anak_aktif_hanya_lewat_transfer(): void
    {
        $baru = Wali::create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Baru', 'telepon_ayah' => '0833',
            'nama' => 'Baru', 'telepon' => '0833', 'status' => 'aktif']);
        (new \App\Services\PortalWali\PinTransaksi($baru, 'ayah'))->simpan(self::PIN);
        DompetWali::create(['id_wali' => $baru->id, 'saldo' => 1000000]);
        $reg = $this->tagihan($this->santri('Calon Baru', 'calon', $baru), 'REG', 'registrasi', 500000);

        $this->actingAs($baru, 'wali')->withSession(['portal.peran' => 'ayah'])
            ->post(route('portal.bayar.dompet', $reg->id), ['nominal' => 500000, 'pin' => self::PIN])->assertSessionHas('error');
        $this->assertSame(0, PembayaranSantri::count());
    }

    /** Registrasi pendaftaran lanjutan santri AKTIF kini dibayar dari dompet di portal. */
    public function test_registrasi_lanjutan_santri_aktif_dari_dompet(): void
    {
        $this->saldo(1000000);
        $reg = $this->tagihan($this->aktif, 'REG', 'registrasi', 250000);

        $this->sebagaiIbu()->post(route('portal.bayar.dompet', $reg->id), ['nominal' => 250000, 'pin' => self::PIN])
            ->assertSessionHas('status');
        $this->assertSame('lunas', $reg->refresh()->status);
    }

    public function test_santri_aktif_tidak_membayar_lewat_transfer_langsung(): void
    {
        $du = $this->tagihan($this->aktif, 'DU', 'daftar_ulang', 600000);

        $this->sebagaiIbu()->post(route('portal.bayar.transfer', $du->id), [
            'kode_rekening' => self::BANK, 'nominal' => 600000, 'tanggal' => now()->toDateString(), 'bukti' => $this->bukti(),
        ])->assertSessionHas('error');

        $this->assertSame(0, PembayaranSantri::count());
        Storage::disk('local')->assertDirectoryEmpty('pembayaran-bukti');
    }

    // ---- B. Isi dompet ----

    public function test_isi_dompet_lewat_transfer_menunggu_verifikasi(): void
    {
        $this->sebagaiIbu()->post(route('portal.dompet.isi'), [
            'kode_rekening' => self::BANK, 'nominal' => 300000, 'tanggal' => now()->toDateString(), 'bukti' => $this->bukti(),
        ])->assertRedirect(route('portal.dompet'));

        $m = MutasiDompet::sole();
        $this->assertSame('menunggu_verifikasi', $m->status);
        $this->assertSame($this->wali->id, $m->dicatat_oleh_wali);
        $this->assertEquals(0, (float) DompetWali::sole()->saldo, 'saldo baru bertambah setelah diverifikasi');
    }

    public function test_rekening_yang_tak_dipilih_untuk_portal_ditolak(): void
    {
        CoaDetail::create(['kode_coa' => '1.ZZPB.OPS', 'nama_coa' => 'Bank Ops', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        BankAccount::create(['kode_coa' => '1.ZZPB.OPS', 'nama_rekening' => 'Rekening Gaji', 'jenis_rekening' => 'bank',
            'nama_bank' => 'BRI', 'no_rekening' => '999', 'status' => 'aktif', 'tampil_di_portal' => false]);

        $this->sebagaiIbu()->get(route('portal.dompet.isi'))->assertOk()->assertSee('7123456789')->assertDontSee('Rekening Gaji');
        $this->sebagaiIbu()->post(route('portal.dompet.isi'), [
            'kode_rekening' => '1.ZZPB.OPS', 'nominal' => 300000, 'tanggal' => now()->toDateString(), 'bukti' => $this->bukti(),
        ])->assertSessionHas('error');
        $this->assertSame(0, MutasiDompet::count());
    }

    public function test_auto_debet_bisa_diaktifkan_dari_portal(): void
    {
        $this->sebagaiIbu()->post(route('portal.dompet.auto_debet'), ['aktif' => '1', 'pin' => self::PIN])->assertRedirect(route('portal.dompet'));
        $this->assertTrue($this->wali->refresh()->auto_debet);

        $this->sebagaiIbu()->post(route('portal.dompet.auto_debet'), ['aktif' => '0']);
        $this->assertFalse($this->wali->refresh()->auto_debet);
    }

    // ---- Layar staf ----

    public function test_keuangan_memilih_rekening_portal(): void
    {
        $this->actingAs($this->keuangan)->post(route('rekening_portal.simpan'), [
            'tampil' => [], 'atas_nama' => [self::BANK => 'Yayasan Al Wafi'],
        ])->assertRedirect(route('rekening_portal.index'));

        $rek = BankAccount::find(self::BANK);
        $this->assertFalse($rek->tampil_di_portal);
        $this->assertSame('Yayasan Al Wafi', $rek->atas_nama);
        $this->assertTrue(BankAccount::untukPortal()->isEmpty());
    }
}
