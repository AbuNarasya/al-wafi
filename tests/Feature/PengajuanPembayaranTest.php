<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\ApprovalFlow;
use App\Models\ApprovalInstance;
use App\Models\Bagian;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JournalEntry;
use App\Models\Level;
use App\Models\LevelPengajuan;
use App\Models\PengajuanPembayaran;
use App\Models\User;
use App\Services\Modules\ApprovalService;
use App\Services\Modules\PengajuanPembayaranService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Alur Pengajuan Pembayaran (accrual): create → rantai → verifikasi (posting §4.g) → bayar. */
class PengajuanPembayaranTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZPP';
    private const BEBAN = '5.ZZPP.1';
    private const HUTANG = '2.ZZPP.1';
    private const UNIT = 'ZZUNIT';

    private PengajuanPembayaranService $svc;
    private ApprovalService $appr;
    private int $staff;
    private int $mudir;
    private int $keuangan;

    protected function setUp(): void
    {
        parent::setUp();
        ApprovalService::resetRegistry();
        // Daftarkan handler pengajuan (deterministik, tak bergantung urutan test).
        ApprovalService::daftarPenolakan(PengajuanPembayaranService::SUMBER, fn ($id) => (new PengajuanPembayaranService)->applyRejected($id));

        $this->svc = new PengajuanPembayaranService;
        $this->appr = new ApprovalService;

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        LevelPengajuan::create(['peringkat' => 3, 'nama' => 'Mudir Bagian']);
        LevelPengajuan::create(['peringkat' => 4, 'nama' => 'Staff']);
        Bagian::create(['kode_bagian' => 'B1', 'nama_bagian' => 'Bagian 1', 'level' => 3]);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'PP Test']);
        CoaDetail::create(['kode_coa' => self::BEBAN, 'nama_coa' => 'Beban ATK', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => self::HUTANG, 'nama_coa' => 'Hutang Pengajuan', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);

        $this->staff = User::create(['username' => 'staff', 'nama' => 'Staff', 'password_hash' => 'x', 'kode_level' => 'L1', 'kode_bagian' => 'B1', 'peringkat_pengajuan' => 4])->id_pengguna;
        $this->mudir = User::create(['username' => 'mudir', 'nama' => 'Mudir', 'password_hash' => 'x', 'kode_level' => 'L1', 'kode_bagian' => 'B1', 'peringkat_pengajuan' => 3])->id_pengguna;
        $this->keuangan = User::create(['username' => 'keu', 'nama' => 'Keuangan', 'password_hash' => 'x', 'kode_level' => 'L1', 'tim_keuangan' => true])->id_pengguna;

        $flow = ApprovalFlow::create(['kode_flow' => 'FPP', 'nama_flow' => 'Pengajuan', 'jenis_dokumen' => PengajuanPembayaranService::SUMBER]);
        $flow->steps()->create(['urutan' => 1, 'nama_tahap' => 'Mudir Bagian', 'peringkat' => 3, 'scope' => 'bagian']);
    }

    private function buatPengajuan(): PengajuanPembayaran
    {
        return $this->svc->create([
            'tanggal' => '2026-07-15', 'jenis' => 'pembayaran', 'keterangan' => 'Beli ATK',
            'details' => [['kode_coa' => self::BEBAN, 'kode_unit' => self::UNIT, 'nominal' => '100000']],
        ], $this->staff);
    }

    public function test_hanya_staff_boleh_mengajukan(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/Hanya Staff/i');
        $this->svc->create([
            'tanggal' => '2026-07-15', 'jenis' => 'pembayaran', 'keterangan' => 'x',
            'details' => [['kode_coa' => self::BEBAN, 'kode_unit' => self::UNIT, 'nominal' => '1000']],
        ], $this->mudir);
    }

    public function test_alur_lengkap_create_approve_verifikasi_bayar(): void
    {
        $p = $this->buatPengajuan();
        $this->assertSame('diajukan', $p->status);
        $this->assertSame(100000.0, (float) $p->nominal);

        $inst = ApprovalInstance::where('jenis_dokumen', PengajuanPembayaranService::SUMBER)->where('id_dokumen', (string) $p->id)->first();

        // Verifikasi sebelum rantai selesai → ditolak.
        try {
            $this->svc->verifikasi($p->id, self::HUTANG, $this->keuangan);
            $this->fail('harus gagal');
        } catch (AppException $e) {
            $this->assertSame(409, $e->status);
        }

        // Mudir menyetujui → rantai selesai.
        $this->appr->approve($inst->id, $this->mudir);
        $this->assertSame('disetujui', $inst->refresh()->status);

        // Keuangan verifikasi → posting §4.g (Beban D / Hutang K), status diposting.
        $this->svc->verifikasi($p->id, self::HUTANG, $this->keuangan);
        $p->refresh();
        $this->assertSame('diposting', $p->status);
        $this->assertSame(100000.0, (float) $p->sisa_hutang);

        $entry = JournalEntry::with('lines')->where('sumber_modul', PengajuanPembayaranService::SUMBER)->where('id_sumber', (string) $p->id)->first();
        $this->assertNotNull($entry);
        $this->assertSame(100000.0, (float) $entry->lines->firstWhere('kode_coa', self::BEBAN)->debet);
        $this->assertSame(100000.0, (float) $entry->lines->firstWhere('kode_coa', self::HUTANG)->kredit);

        // Kas Keluar melunasi (§4.h) — harus PENUH.
        $this->svc->applyPayment($p->id, '100000');
        $this->assertSame('lunas', $p->refresh()->status);
        $this->assertSame(0.0, (float) $p->sisa_hutang);
    }

    /**
     * PEMBAYARAN SEBAGIAN kini DIIZINKAN — vendor lazim dibayar bertahap.
     *
     * Dulu dilarang bukan karena aturan usaha, melainkan karena baris hutangnya
     * dipecah per unit: cicilan memaksa porsi tiap unit diprorata, lengkap
     * dengan sisa pembulatan yang tak punya rumah. Sejak hutang & kas duduk di
     * unit penampung neraca, pembagian itu tak ada lagi.
     *
     * Statusnya tetap `diposting` selama masih bersisa — yang menentukan boleh
     * dibayar lagi memang sisanya, bukan namanya.
     */
    public function test_pembayaran_boleh_dicicil_sampai_lunas(): void
    {
        $p = $this->buatPengajuan();
        $inst = ApprovalInstance::where('jenis_dokumen', PengajuanPembayaranService::SUMBER)->where('id_dokumen', (string) $p->id)->first();
        $this->appr->approve($inst->id, $this->mudir);
        $this->svc->verifikasi($p->id, self::HUTANG, $this->keuangan);

        $this->svc->applyPayment($p->id, '30000');
        $p->refresh();
        $this->assertSame('diposting', $p->status, 'masih bersisa — belum boleh disebut lunas');
        $this->assertSame(70000.0, (float) $p->sisa_hutang);

        $this->svc->applyPayment($p->id, '70000');
        $p->refresh();
        $this->assertSame('lunas', $p->status);
        $this->assertSame(0.0, (float) $p->sisa_hutang);
    }

    /** Yang tetap dilarang: melebihi sisa, dan nol/negatif. */
    public function test_cicilan_tak_boleh_melebihi_sisa_hutang(): void
    {
        $p = $this->buatPengajuan();
        $inst = ApprovalInstance::where('jenis_dokumen', PengajuanPembayaranService::SUMBER)->where('id_dokumen', (string) $p->id)->first();
        $this->appr->approve($inst->id, $this->mudir);
        $this->svc->verifikasi($p->id, self::HUTANG, $this->keuangan);
        $this->svc->applyPayment($p->id, '60000');

        try {
            $this->svc->applyPayment($p->id, '50000');
            $this->fail('pembayaran melebihi sisa seharusnya ditolak');
        } catch (AppException $e) {
            $this->assertStringContainsString('melebihi sisa hutang', $e->getMessage());
        }

        try {
            $this->svc->applyPayment($p->id, '0');
            $this->fail('nominal nol seharusnya ditolak');
        } catch (AppException $e) {
            $this->assertStringContainsString('lebih dari nol', $e->getMessage());
        }

        $this->assertSame(40000.0, (float) $p->refresh()->sisa_hutang, 'sisa tak boleh bergeser oleh percobaan yang gagal');
    }

    /**
     * Lembar cetak: A4 portrait. Dulu A5 landscape (148mm) — tinggi isinya
     * tumbuh mengikuti jumlah baris rincian DAN jumlah tahap approval, jadi
     * mudah terpotong jadi dua halaman seperti kuitansi dulu.
     */
    public function test_cetak_memakai_a4_portrait(): void
    {
        $p = $this->buatPengajuan();
        $inst = ApprovalInstance::where('jenis_dokumen', PengajuanPembayaranService::SUMBER)->where('id_dokumen', (string) $p->id)->first();
        $this->appr->approve($inst->id, $this->mudir);

        // status WAJIB 'aktif' di objek yang sama: default kolom hanya berlaku
        // di baris DB, sedangkan Akses membaca model yang ada di memori.
        $admin = User::create([
            'username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif',
        ]);

        $this->actingAs($admin)->get("/pengajuan-pembayaran/{$p->id}/cetak")
            ->assertOk()
            ->assertSee('size: A4 portrait', false)
            ->assertDontSee('A5 landscape', false)
            ->assertSee('PENGAJUAN PEMBAYARAN')
            ->assertSee($p->nomor)
            // Blok tanda tangan ikut terisi: pemohon + approver yang sudah setuju.
            ->assertSee('Diajukan oleh')
            ->assertSee('Mudir');
    }

    public function test_tolak_menandai_pengajuan_ditolak(): void
    {
        $p = $this->buatPengajuan();
        $inst = ApprovalInstance::where('jenis_dokumen', PengajuanPembayaranService::SUMBER)->where('id_dokumen', (string) $p->id)->first();
        $this->appr->reject($inst->id, $this->mudir, 'kurang bukti');
        $this->assertSame('ditolak', $p->refresh()->status);
    }
}
