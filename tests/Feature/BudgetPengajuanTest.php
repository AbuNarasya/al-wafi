<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\ApprovalFlow;
use App\Models\ApprovalInstance;
use App\Models\ApprovalLog;
use App\Models\Bagian;
use App\Models\Budget;
use App\Models\BudgetPengajuan;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\CompanySettings;
use App\Models\HakAksesModul;
use App\Models\Level;
use App\Models\LevelPengajuan;
use App\Models\Notification;
use App\Models\User;
use App\Services\Modules\ApprovalService;
use App\Services\Modules\BudgetLockService;
use App\Services\Modules\BudgetPengajuanService;
use App\Services\Modules\BudgetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §3.c Pengajuan Anggaran lewat BUDGET-STD (Mudir Bagian → Mudir Umum → Ketua).
 * Port budget-pengajuan.test.ts + kunci anggaran + jalur HTTP-nya.
 *
 * bulan_awal_anggaran = 1 supaya Tahun Anggaran = tahun kalender di test ini.
 */
class BudgetPengajuanTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZBP';
    private const BEBAN = 'ZZBP.BEBAN';
    private const BEBAN2 = 'ZZBP.BEBAN2';
    private const KAS = 'ZZBP.KAS';
    private const BAG = 'ZZBP-BAG';
    private const UNIT = 'ZZBPUNIT';

    private BudgetPengajuanService $svc;
    private ApprovalService $appr;
    private BudgetLockService $lock;
    private int $staff;
    private int $mudirBagian;
    private int $mudirUmum;
    private int $ketua;

    protected function setUp(): void
    {
        parent::setUp();

        // Registry statis: daftar ulang persis seperti AppServiceProvider agar
        // test tak bergantung urutan eksekusi test lain.
        ApprovalService::resetRegistry();
        ApprovalService::daftarHandler(BudgetPengajuanService::SUMBER, fn ($id) => (new BudgetPengajuanService)->applyApproved($id));
        ApprovalService::daftarPenolakan(BudgetPengajuanService::SUMBER, fn ($id) => (new BudgetPengajuanService)->applyRejected($id));

        $this->svc = new BudgetPengajuanService;
        $this->appr = new ApprovalService;
        $this->lock = new BudgetLockService;

        CompanySettings::create([
            'id' => 1, 'nama_perusahaan' => 'TEST',
            'periode_awal_pembukuan' => '2099-01-01', 'bulan_awal_anggaran' => 1,
        ]);

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        foreach ([1 => 'Ketua Yayasan', 2 => 'Mudir Umum', 3 => 'Mudir Bagian', 4 => 'Staff'] as $p => $nama) {
            LevelPengajuan::create(['peringkat' => $p, 'nama' => $nama]);
        }
        Bagian::create(['kode_bagian' => self::BAG, 'nama_bagian' => 'Bagian BP', 'level' => 3]);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit BP']);

        // Grup "5" harus ada: rootOf() menelusuri kode_induk untuk memastikan
        // akunnya benar-benar kelompok Beban.
        CoaGroup::create(['kode_grup' => '5', 'nama_grup' => 'Beban']);
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'BP Test']);
        CoaGroup::create(['kode_grup' => self::GRP.'5', 'nama_grup' => 'BP Beban', 'kode_induk' => '5']);
        CoaDetail::create(['kode_coa' => self::BEBAN, 'nama_coa' => 'Beban BP', 'kode_grup' => self::GRP.'5', 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => self::BEBAN2, 'nama_coa' => 'Beban BP 2', 'kode_grup' => self::GRP.'5', 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => self::KAS, 'nama_coa' => 'Kas BP', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);

        $this->staff = $this->buatUser('zzbp_staff', 4, self::BAG);
        $this->mudirBagian = $this->buatUser('zzbp_kabag', 3, self::BAG);
        $this->mudirUmum = $this->buatUser('zzbp_umum', 2, null);
        $this->ketua = $this->buatUser('zzbp_ketua', 1, null);

        $flow = ApprovalFlow::create(['kode_flow' => 'BUDGET-STD', 'nama_flow' => 'Pengajuan Anggaran', 'jenis_dokumen' => BudgetPengajuanService::SUMBER]);
        $flow->steps()->create(['urutan' => 1, 'nama_tahap' => 'Mudir Bagian', 'peringkat' => 3, 'scope' => 'bagian']);
        $flow->steps()->create(['urutan' => 2, 'nama_tahap' => 'Mudir Umum', 'peringkat' => 2, 'scope' => 'yayasan']);
        $flow->steps()->create(['urutan' => 3, 'nama_tahap' => 'Ketua Yayasan', 'peringkat' => 1, 'scope' => 'yayasan']);
    }

    /** status WAJIB 'aktif' di objek yang sama — default kolom hanya berlaku di baris DB. */
    private function buatUser(string $username, ?int $peringkat, ?string $kodeBagian): int
    {
        return User::create([
            'username' => $username, 'nama' => "BP {$username}", 'password_hash' => 'x',
            'kode_level' => 'L1', 'peringkat_pengajuan' => $peringkat, 'kode_bagian' => $kodeBagian,
            'status' => 'aktif',
        ])->id_pengguna;
    }

    private function instanceOf(int $idDok): ?ApprovalInstance
    {
        return ApprovalInstance::where('jenis_dokumen', BudgetPengajuanService::SUMBER)
            ->where('id_dokumen', (string) $idDok)->first();
    }

    private function setujuiPenuh(int $idDok): void
    {
        $inst = $this->instanceOf($idDok);
        $this->appr->approve($inst->id, $this->mudirBagian); // tahap 1
        $this->appr->approve($inst->id, $this->mudirUmum);   // tahap 2
        $this->appr->approve($inst->id, $this->ketua);       // tahap 3 → selesai → applyApproved
    }

    /** @param array<int,array{kode_coa:string,bulan:int,nominal:string}> $items */
    private function ajukan(int $tahun, array $items, ?int $oleh = null, ?string $unit = null): BudgetPengajuan
    {
        return $this->svc->create(
            ['tahun' => $tahun, 'kode_unit' => $unit, 'items' => $items],
            $oleh ?? $this->staff,
        );
    }

    public function test_staff_mengajukan_rantai_penuh_menulis_anggaran_live(): void
    {
        $ta = 2097;
        $p = $this->ajukan($ta, [
            ['kode_coa' => self::BEBAN, 'bulan' => 1, 'nominal' => '1000000'],
            ['kode_coa' => self::BEBAN, 'bulan' => 2, 'nominal' => '500000'],
        ]);
        $this->assertSame('diajukan', $p->status);
        $this->assertStringStartsWith('PA-', $p->nomor);

        // Belum ada anggaran live sebelum disetujui — usulan menggantung bukan anggaran.
        $this->assertSame(0, Budget::where('kode_bagian', self::BAG)->where('tahun', $ta)->count());

        $this->setujuiPenuh($p->id);

        $this->assertSame('disetujui', $p->refresh()->status);
        $jan = Budget::where('kode_bagian', self::BAG)->where('tahun', $ta)->where('bulan', 1)->where('kode_coa', self::BEBAN)->first();
        $feb = Budget::where('kode_bagian', self::BAG)->where('tahun', $ta)->where('bulan', 2)->where('kode_coa', self::BEBAN)->first();
        $this->assertSame(1000000.0, (float) $jan->nominal);
        $this->assertSame(500000.0, (float) $feb->nominal);
    }

    public function test_mudir_bagian_mengajukan_tahap_bagian_disetujui_otomatis(): void
    {
        $p = $this->ajukan(2096, [['kode_coa' => self::BEBAN, 'bulan' => 3, 'nominal' => '700000']], $this->mudirBagian);

        $inst = $this->instanceOf($p->id);
        // Sudah di Mudir Umum (2) — ia tidak menyetujui dirinya sendiri di tahap 1.
        $this->assertSame(2, $inst->tahap_sekarang);
        $this->assertTrue(
            ApprovalLog::where('id_instance', $inst->id)->where('aksi', 'approve')->get()
                ->contains(fn ($l) => str_contains((string) $l->catatan, 'otomatis')),
        );
    }

    public function test_ditolak_tidak_menulis_anggaran(): void
    {
        $ta = 2095;
        $p = $this->ajukan($ta, [['kode_coa' => self::BEBAN, 'bulan' => 1, 'nominal' => '999000']]);
        $this->appr->reject($this->instanceOf($p->id)->id, $this->mudirBagian, 'tidak sesuai');

        $this->assertSame('ditolak', $p->refresh()->status);
        $this->assertSame(0, Budget::where('kode_bagian', self::BAG)->where('tahun', $ta)->count());
    }

    public function test_penerapan_mengganti_scope_penuh(): void
    {
        $ta = 2094;
        // Anggaran lama untuk BEBAN2 — akan HILANG karena tak ada di usulan baru.
        Budget::create(['tahun' => $ta, 'bulan' => 1, 'kode_coa' => self::BEBAN2, 'kode_bagian' => self::BAG, 'kode_unit' => null, 'nominal' => '12345']);

        $p = $this->ajukan($ta, [['kode_coa' => self::BEBAN, 'bulan' => 1, 'nominal' => '2000000']]);
        $this->setujuiPenuh($p->id);

        $this->assertSame(0, Budget::where('kode_bagian', self::BAG)->where('tahun', $ta)->where('kode_coa', self::BEBAN2)->count());
        $this->assertSame(
            2000000.0,
            (float) Budget::where('kode_bagian', self::BAG)->where('tahun', $ta)->where('kode_coa', self::BEBAN)->value('nominal'),
        );
    }

    /** Scope = (bagian, unit): usulan unit tertentu tak boleh menghapus anggaran "semua unit". */
    public function test_penerapan_tidak_menyentuh_scope_unit_lain(): void
    {
        $ta = 2093;
        Budget::create(['tahun' => $ta, 'bulan' => 1, 'kode_coa' => self::BEBAN2, 'kode_bagian' => self::BAG, 'kode_unit' => null, 'nominal' => '777']);

        $p = $this->ajukan($ta, [['kode_coa' => self::BEBAN, 'bulan' => 1, 'nominal' => '111']], null, self::UNIT);
        $this->setujuiPenuh($p->id);

        $this->assertSame(777.0, (float) Budget::where('kode_bagian', self::BAG)->where('tahun', $ta)->whereNull('kode_unit')->value('nominal'));
        $this->assertSame(111.0, (float) Budget::where('kode_bagian', self::BAG)->where('tahun', $ta)->where('kode_unit', self::UNIT)->value('nominal'));
    }

    public function test_hanya_staff_atau_mudir_bagian_yang_boleh_mengajukan(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/Staff atau Mudir Bagian/');
        $this->ajukan(2092, [['kode_coa' => self::BEBAN, 'bulan' => 1, 'nominal' => '1000']], $this->mudirUmum);
    }

    public function test_hanya_akun_beban_yang_boleh_dianggarkan(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/bukan akun Beban/');
        $this->ajukan(2091, [['kode_coa' => self::KAS, 'bulan' => 1, 'nominal' => '1000']]);
    }

    public function test_batal_oleh_pemohon_menutup_rantai(): void
    {
        $p = $this->ajukan(2090, [['kode_coa' => self::BEBAN, 'bulan' => 1, 'nominal' => '5000']]);
        $this->svc->batal($p->id, $this->staff);

        $this->assertSame('dibatalkan', $p->refresh()->status);
        $this->assertSame('dibatalkan', $this->instanceOf($p->id)->status);
    }

    public function test_ta_terkunci_menolak_pengajuan_baru_dan_simpan_langsung(): void
    {
        $ta = 2088;
        $this->lock->kunci($ta, $this->staff, 'final');
        $this->assertTrue((new BudgetService)->grid($ta, self::BAG)['terkunci']);

        try {
            $this->ajukan($ta, [['kode_coa' => self::BEBAN, 'bulan' => 1, 'nominal' => '1000']]);
            $this->fail('pengajuan harus ditolak saat TA terkunci');
        } catch (AppException $e) {
            $this->assertMatchesRegularExpression('/terkunci/i', $e->getMessage());
        }

        // Admin pun terblokir (save = jalur admin).
        try {
            (new BudgetService)->save(['tahun' => $ta, 'kode_bagian' => self::BAG, 'kode_unit' => null,
                'items' => [['kode_coa' => self::BEBAN, 'bulan' => 1, 'nominal' => '1000']]]);
            $this->fail('simpan langsung harus ditolak saat TA terkunci');
        } catch (AppException $e) {
            $this->assertMatchesRegularExpression('/terkunci/i', $e->getMessage());
        }

        // Dibuka → keduanya jalan lagi.
        $this->lock->buka($ta);
        (new BudgetService)->save(['tahun' => $ta, 'kode_bagian' => self::BAG, 'kode_unit' => null,
            'items' => [['kode_coa' => self::BEBAN, 'bulan' => 1, 'nominal' => '1000']]]);
        $this->assertSame(1, Budget::where('kode_bagian', self::BAG)->where('tahun', $ta)->count());
    }

    /**
     * Kunci berlaku per TAHUN ANGGARAN (seluruh bagian & unit), jadi tombolnya
     * harus terlihat TANPA memilih bagian dulu — dulu ia bersembunyi di toolbar
     * yang baru muncul setelah sebuah bagian dipilih.
     */
    public function test_tombol_kunci_muncul_tanpa_memilih_bagian(): void
    {
        $admin = User::create([
            'username' => 'zzbp_adm2', 'nama' => 'Admin BP', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif',
        ]);

        $this->actingAs($admin)->get('/budget?tahun=2085')->assertOk()
            ->assertSee('Kunci Anggaran')
            ->assertSee('Pilih'); // belum ada bagian terpilih, gridnya memang belum tampil

        $this->lock->kunci(2085, $admin->id_pengguna);
        $this->actingAs($admin)->get('/budget?tahun=2085')->assertOk()
            ->assertSee('Buka Kunci')
            ->assertDontSee('🔒 Kunci Anggaran', false);

        // Non-admin tak pernah ditawari keduanya.
        HakAksesModul::create([
            'id_pengguna' => $this->staff, 'kode_modul' => 'budget',
            'lihat' => true, 'buat' => false, 'ubah' => false, 'hapus' => false, 'menu' => true,
        ]);
        $this->actingAs(User::find($this->staff))->get('/budget?tahun=2085')->assertOk()
            ->assertDontSee('Buka Kunci');
    }

    public function test_dikunci_di_tengah_rantai_tidak_menimpa_anggaran_beku(): void
    {
        $ta = 2089;
        $p = $this->ajukan($ta, [['kode_coa' => self::BEBAN, 'bulan' => 1, 'nominal' => '2000000']]);

        // Admin mengunci SETELAH pengajuan berjalan.
        $this->lock->kunci($ta, $this->staff);
        $this->setujuiPenuh($p->id); // rantai selesai → applyApproved kena kunci

        $this->assertSame('ditolak', $p->refresh()->status);
        $this->assertSame(0, Budget::where('kode_bagian', self::BAG)->where('tahun', $ta)->count());
        $this->assertSame(1, Notification::where('jenis', 'budget_terkunci')
            ->where('ref_jenis', BudgetPengajuanService::SUMBER)->where('ref_id', (string) $p->id)->count());
    }

    public function test_orang_bagian_lain_tidak_boleh_melihat_pengajuan(): void
    {
        $p = $this->ajukan(2087, [['kode_coa' => self::BEBAN, 'bulan' => 1, 'nominal' => '1000']]);

        Bagian::create(['kode_bagian' => 'ZZBP-LAIN', 'nama_bagian' => 'Bagian Lain', 'level' => 3]);
        $orang = $this->buatUser('zzbp_lain', 4, 'ZZBP-LAIN');

        try {
            $this->svc->get($p->id, $orang);
            $this->fail('orang bagian lain tidak boleh melihat');
        } catch (AppException $e) {
            $this->assertSame(403, $e->status);
        }

        // Ketua Yayasan & pemohon tetap boleh.
        $this->assertSame($p->id, $this->svc->get($p->id, $this->ketua)->id);
        $this->assertSame($p->id, $this->svc->get($p->id, $this->staff)->id);
    }

    /** Jalur HTTP: form → simpan → halaman detail, digerbangi hak modul 'budget'. */
    public function test_alur_http_ajukan_dan_lihat(): void
    {
        $staffUser = User::find($this->staff);
        HakAksesModul::create([
            'id_pengguna' => $this->staff, 'kode_modul' => 'budget',
            'lihat' => true, 'buat' => true, 'ubah' => false, 'hapus' => false, 'menu' => true,
        ]);

        $this->actingAs($staffUser)->get('/budget/pengajuan/buat')->assertOk()
            ->assertSee('Ajukan Anggaran')
            ->assertSee('Bagian BP');

        $this->actingAs($staffUser)->post('/budget/pengajuan', [
            'keterangan' => 'Usulan rutin',
            'payload' => json_encode(['tahun' => 2086, 'kode_unit' => '', 'items' => [
                ['kode_coa' => self::BEBAN, 'bulan' => 1, 'nominal' => '250000'],
            ]]),
        ])->assertRedirect();

        $rec = BudgetPengajuan::where('tahun', 2086)->firstOrFail();
        $this->assertSame('diajukan', $rec->status);
        $this->assertSame('Usulan rutin', $rec->keterangan);

        $this->actingAs($staffUser)->get("/budget/pengajuan/{$rec->id}")->assertOk()
            ->assertSee($rec->nomor)
            ->assertSee('Beban BP')
            ->assertSee('Menunggu persetujuan', false);

        $this->actingAs($staffUser)->get('/budget/pengajuan')->assertOk()
            ->assertSee($rec->nomor)
            ->assertSee('Ajukan Anggaran'); // menu + tombol ada bagi Staff
    }

    /**
     * Admin tanpa peringkat pengajuan TIDAK ditawari "Ajukan Anggaran": ia tak
     * punya bagian/peringkat sehingga formnya akan menolaknya — menu mati.
     * Jalur admin adalah Input Anggaran (simpan langsung).
     */
    public function test_admin_tanpa_peringkat_tidak_ditawari_menu_ajukan(): void
    {
        $admin = User::create([
            'username' => 'zzbp_admin', 'nama' => 'Admin BP', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif',
        ]);

        $this->actingAs($admin)->get('/budget/pengajuan')->assertOk()
            ->assertSee('Input Anggaran')       // menu anggaran memang tampil…
            ->assertDontSee('Ajukan Anggaran'); // …tapi jalur pengajuan tidak
    }

    /** Tanpa hak modul 'budget' → tidak boleh menyentuh pengajuan anggaran. */
    public function test_tanpa_hak_modul_ditolak(): void
    {
        $this->actingAs(User::find($this->staff))->get('/budget/pengajuan')->assertForbidden();
    }
}
