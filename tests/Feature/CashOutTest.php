<?php

namespace Tests\Feature;

use App\Models\ApprovalFlow;
use App\Models\ApprovalInstance;
use App\Models\BankAccount;
use App\Models\Bagian;
use App\Models\BusinessUnit;
use App\Models\CashOut;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Level;
use App\Models\LevelPengajuan;
use App\Models\PengajuanPembayaran;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorType;
use App\Services\Modules\ApprovalService;
use App\Services\Modules\CashOutService;
use App\Services\Modules\InvoiceService;
use App\Services\Modules\PengajuanPembayaranService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Integrasi Kas Keluar: lainnya, bayar invoice, lunasi pengajuan, void. */
class CashOutTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZKK';
    private const KAS = 'ZZKK.KAS';
    private const BEBAN = 'ZZKK.BEBAN';
    private const HUTANG_INV = '2.ZZKK.INV';
    private const BEBAN_PB = '5.ZZKK.PB';
    private const HUTANG_PB = '2.ZZKK.PB';
    private const UNIT = 'ZZUNIT';

    private CashOutService $svc;
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        ApprovalService::resetRegistry();
        $this->svc = new CashOutService;

        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'KK Test']);
        foreach ([[self::KAS, 'Kas', 'debet'], [self::BEBAN, 'Beban', 'debet'], [self::HUTANG_INV, 'Hutang Usaha', 'kredit'], [self::BEBAN_PB, 'Beban PB', 'debet'], [self::HUTANG_PB, 'Hutang Pengajuan', 'kredit']] as [$k, $n, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => self::GRP, 'jenis_saldo' => $s]);
        }
        BankAccount::create(['kode_coa' => self::KAS, 'nama_rekening' => 'Kas', 'jenis_rekening' => 'bank']);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);
        Bagian::create(['kode_bagian' => 'B1', 'nama_bagian' => 'Bagian', 'level' => 3]);
        VendorType::create(['kode_jenis_vendor' => 'JV', 'nama' => 'Umum']);
        Vendor::create(['kode_vendor' => 'V1', 'nama_vendor' => 'PT V', 'kode_jenis_vendor' => 'JV']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->admin = User::create(['username' => 'admin', 'nama' => 'Admin', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => true])->id_pengguna;
    }

    public function test_lainnya_debit_beban_kredit_kas_dan_void(): void
    {
        $rec = $this->svc->create([
            'tanggal' => '2026-07-06', 'kode_unit' => self::UNIT, 'kode_rekening' => self::KAS,
            'keterangan' => 'Bayar beban',
            'details' => [['tipe' => 'lainnya', 'kode_coa' => self::BEBAN, 'nominal' => '75000']],
        ], $this->admin);

        $this->assertSame('KK-2607-0001', $rec->nomor_transaksi);
        $entry = JournalEntry::with('lines')->where('sumber_modul', 'KasKeluar')->where('id_sumber', (string) $rec->kode_transaksi)->first();
        $this->assertSame(75000.0, (float) $entry->lines->firstWhere('kode_coa', self::BEBAN)->debet);
        $this->assertSame(75000.0, (float) $entry->lines->firstWhere('kode_coa', self::KAS)->kredit);

        // Void → reversal + status void.
        $this->svc->void($rec->kode_transaksi, ['alasan' => 'salah'], $this->admin, 'Admin');
        $this->assertSame('void', $rec->refresh()->status);
        $this->assertNotNull(JournalEntry::where('reversal_of', $entry->id)->first());
    }

    public function test_bayar_invoice_mengurangi_sisa_hutang(): void
    {
        $inv = (new InvoiceService)->create([
            'nomor_invoice' => 'INV-1', 'tanggal_invoice' => '2026-07-01', 'tanggal_jatuh_tempo' => '2026-08-01',
            'kode_vendor' => 'V1', 'kode_unit' => self::UNIT, 'kode_coa_hutang' => self::HUTANG_INV,
            'details' => [['kode_coa' => self::BEBAN, 'kuantiti' => '1', 'harga_satuan' => '250000']],
        ], $this->admin);

        $this->svc->create([
            'tanggal' => '2026-07-10', 'kode_unit' => self::UNIT, 'kode_rekening' => self::KAS, 'kode_vendor' => 'V1',
            'keterangan' => 'Bayar invoice',
            'details' => [['tipe' => 'invoice', 'id_invoice' => $inv->id_invoice, 'nominal' => '250000']],
        ], $this->admin);

        $inv->refresh();
        $this->assertSame('lunas', $inv->status);
        $this->assertSame(0.0, (float) $inv->sisa_hutang);
    }

    public function test_lunasi_pengajuan_pembayaran(): void
    {
        // Rantai pengajuan 1 tahap (Mudir Bagian).
        LevelPengajuan::create(['peringkat' => 3, 'nama' => 'Mudir']);
        LevelPengajuan::create(['peringkat' => 4, 'nama' => 'Staff']);
        $staff = User::create(['username' => 'staff', 'nama' => 'Staff', 'password_hash' => 'x', 'kode_level' => 'L1', 'kode_bagian' => 'B1', 'peringkat_pengajuan' => 4])->id_pengguna;
        $mudir = User::create(['username' => 'mudir', 'nama' => 'Mudir', 'password_hash' => 'x', 'kode_level' => 'L1', 'kode_bagian' => 'B1', 'peringkat_pengajuan' => 3])->id_pengguna;
        $keuangan = User::create(['username' => 'keu', 'nama' => 'Keu', 'password_hash' => 'x', 'kode_level' => 'L1', 'tim_keuangan' => true])->id_pengguna;
        $flow = ApprovalFlow::create(['kode_flow' => 'FPP', 'nama_flow' => 'PP', 'jenis_dokumen' => PengajuanPembayaranService::SUMBER]);
        $flow->steps()->create(['urutan' => 1, 'nama_tahap' => 'Mudir', 'peringkat' => 3, 'scope' => 'bagian']);

        $pengajuanSvc = new PengajuanPembayaranService;
        $pb = $pengajuanSvc->create([
            'tanggal' => '2026-07-15', 'jenis' => 'pembayaran', 'keterangan' => 'ATK',
            'details' => [['kode_coa' => self::BEBAN_PB, 'kode_unit' => self::UNIT, 'nominal' => '100000']],
        ], $staff);
        $inst = ApprovalInstance::where('id_dokumen', (string) $pb->id)->first();
        (new ApprovalService)->approve($inst->id, $mudir);
        $pengajuanSvc->verifikasi($pb->id, self::HUTANG_PB, $keuangan);
        $this->assertSame('diposting', $pb->refresh()->status);

        // Lunasi lewat Kas Keluar (tanpa kode_unit — hanya baris pengajuan).
        $this->svc->create([
            'tanggal' => '2026-07-20', 'kode_rekening' => self::KAS, 'keterangan' => 'Lunasi pengajuan',
            'details' => [['tipe' => 'pengajuan', 'id_pengajuan' => $pb->id, 'nominal' => '100000']],
        ], $this->admin);

        $pb->refresh();
        $this->assertSame('lunas', $pb->status);
        $this->assertSame(0.0, (float) $pb->sisa_hutang);
    }
}
