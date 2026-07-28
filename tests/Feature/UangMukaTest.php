<?php

namespace Tests\Feature;

use App\Models\ApprovalFlow;
use App\Models\ApprovalInstance;
use App\Models\Bagian;
use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JournalEntry;
use App\Models\Level;
use App\Models\LevelPengajuan;
use App\Models\OperationalAdvance;
use App\Models\User;
use App\Services\Modules\AdvanceSettlementService;
use App\Services\Modules\ApprovalService;
use App\Services\Modules\CashOutService;
use App\Services\Modules\OperationalAdvanceService;
use App\Services\Modules\PengajuanPembayaranService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Bagian uang muka: operasional, penyelesaian, pengajuan uang muka via Kas Keluar. */
class UangMukaTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZUM';
    private const UM = '1.ZZUM.UM';
    private const KAS = 'ZZUM.KAS';
    private const REAL = '5.ZZUM.REAL';
    private const UNIT = 'ZZUNIT';

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        ApprovalService::resetRegistry();

        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'UM Test']);
        foreach ([[self::UM, 'Uang Muka', 'debet'], [self::KAS, 'Kas', 'debet'], [self::REAL, 'Beban Realisasi', 'debet']] as [$k, $n, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => self::GRP, 'jenis_saldo' => $s]);
        }
        BankAccount::create(['kode_coa' => self::KAS, 'nama_rekening' => 'Kas', 'jenis_rekening' => 'bank']);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);
        Bagian::create(['kode_bagian' => 'B1', 'nama_bagian' => 'Bagian', 'level' => 3]);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->admin = User::create(['username' => 'admin', 'nama' => 'Admin', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => true])->id_pengguna;
    }

    public function test_uang_muka_operasional_lalu_penyelesaian(): void
    {
        $adv = (new OperationalAdvanceService)->create([
            'tanggal' => '2026-07-01', 'kode_unit' => self::UNIT, 'kode_rekening' => self::KAS,
            'kode_coa_uang_muka' => self::UM, 'keterangan' => 'UM belanja', 'nominal' => '500000', 'kode_bagian' => 'B1',
        ], $this->admin);

        $this->assertSame('500000.00', $adv->sisa);
        $this->assertSame('outstanding', $adv->status);
        $entry = JournalEntry::with('lines')->where('sumber_modul', 'UangMukaOperasional')->where('id_sumber', (string) $adv->id)->first();
        $this->assertSame(500000.0, (float) $entry->lines->firstWhere('kode_coa', self::UM)->debet);
        $this->assertSame(500000.0, (float) $entry->lines->firstWhere('kode_coa', self::KAS)->kredit);

        // Penyelesaian: realisasi 450.000 (< uang muka) → pengembalian 50.000.
        (new AdvanceSettlementService)->create([
            'tanggal' => '2026-07-05', 'kode_unit' => self::UNIT, 'id_uang_muka' => $adv->id,
            'nominal_uang_muka' => '500000', 'kode_coa_realisasi' => self::REAL, 'nominal_realisasi' => '450000',
            'kode_rekening' => self::KAS, 'kode_bagian' => 'B1', 'keterangan' => 'Realisasi UM',
        ], $this->admin);

        $adv->refresh();
        $this->assertSame('selesai', $adv->status);
        $this->assertSame('0.00', $adv->sisa);
    }

    public function test_pengajuan_uang_muka_dibayar_kas_keluar(): void
    {
        LevelPengajuan::create(['peringkat' => 3, 'nama' => 'Mudir']);
        LevelPengajuan::create(['peringkat' => 4, 'nama' => 'Staff']);
        $staff = User::create(['username' => 'staff', 'nama' => 'Staff', 'password_hash' => 'x', 'kode_level' => 'L1', 'kode_bagian' => 'B1', 'peringkat_pengajuan' => 4])->id_pengguna;
        $mudir = User::create(['username' => 'mudir', 'nama' => 'Mudir', 'password_hash' => 'x', 'kode_level' => 'L1', 'kode_bagian' => 'B1', 'peringkat_pengajuan' => 3])->id_pengguna;
        $keuangan = User::create(['username' => 'keu', 'nama' => 'Keu', 'password_hash' => 'x', 'kode_level' => 'L1', 'tim_keuangan' => true])->id_pengguna;
        $flow = ApprovalFlow::create(['kode_flow' => 'FPP', 'nama_flow' => 'PP', 'jenis_dokumen' => PengajuanPembayaranService::SUMBER]);
        $flow->steps()->create(['urutan' => 1, 'nama_tahap' => 'Mudir', 'peringkat' => 3, 'scope' => 'bagian']);

        $pengajuanSvc = new PengajuanPembayaranService;
        // Pengajuan uang muka: akun ASET (kelompok 1).
        $pb = $pengajuanSvc->create([
            'tanggal' => '2026-07-10', 'jenis' => 'uang_muka', 'keterangan' => 'UM kegiatan',
            'details' => [['kode_coa' => self::UM, 'kode_unit' => self::UNIT, 'nominal' => '300000']],
        ], $staff);

        $inst = ApprovalInstance::where('id_dokumen', (string) $pb->id)->first();
        (new ApprovalService)->approve($inst->id, $mudir);
        // Verifikasi uang muka → diverifikasi (tanpa posting).
        $pengajuanSvc->verifikasi($pb->id, null, $keuangan);
        $this->assertSame('diverifikasi', $pb->refresh()->status);

        // Kas Keluar melunasi uang muka → jurnal UM(D)/Kas(K) + advance ke pool.
        (new CashOutService)->create([
            'tanggal' => '2026-07-12', 'kode_rekening' => self::KAS, 'keterangan' => 'Bayar uang muka',
            'details' => [['tipe' => 'pengajuan', 'id_pengajuan' => $pb->id, 'nominal' => '300000']],
        ], $this->admin);

        $pb->refresh();
        $this->assertSame('lunas', $pb->status);

        $adv = OperationalAdvance::where('id_pengajuan_sumber', $pb->id)->first();
        $this->assertNotNull($adv);
        $this->assertSame('outstanding', $adv->status);
        $this->assertSame('300000.00', $adv->sisa);
    }
}
