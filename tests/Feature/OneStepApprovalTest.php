<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CashIn;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JournalEntry;
use App\Models\Level;
use App\Models\User;
use App\Services\Modules\CashInService;
use App\Services\Modules\PostingApprovalService;
use App\Services\Modules\VoidApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Approval satu-langkah: void & posting berdasarkan batas otorisasi (max_transaksi). */
class OneStepApprovalTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZ1S';
    private const KAS = 'ZZ1S.KAS';
    private const PEND = 'ZZ1S.PEND';
    private const BEBAN = 'ZZ1S.BEBAN';
    private const UNIT = 'ZZUNIT';

    private int $low;
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();

        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => '1S']);
        foreach ([[self::KAS, 'Kas', 'debet'], [self::PEND, 'Pendapatan', 'kredit'], [self::BEBAN, 'Beban', 'debet']] as [$k, $n, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => self::GRP, 'jenis_saldo' => $s]);
        }
        BankAccount::create(['kode_coa' => self::KAS, 'nama_rekening' => 'Kas', 'jenis_rekening' => 'bank']);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);

        Level::create(['kode_level' => 'LOW', 'nama_level' => 'Staff', 'max_transaksi' => '50000']);
        Level::create(['kode_level' => 'HIGH', 'nama_level' => 'Direktur', 'max_transaksi' => null]);
        $this->low = User::create(['username' => 'low', 'nama' => 'Low', 'password_hash' => 'x', 'kode_level' => 'LOW'])->id_pengguna;
        $this->admin = User::create(['username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x', 'kode_level' => 'HIGH'])->id_pengguna;
    }

    private function buatKasMasuk(string $nominal): CashIn
    {
        return (new CashInService)->create([
            'tanggal' => '2026-07-05', 'kode_unit' => self::UNIT, 'kode_rekening' => self::KAS,
            'keterangan' => 'KM', 'details' => [['kode_coa' => self::PEND, 'nominal' => $nominal]],
        ], $this->admin);
    }

    public function test_void_di_atas_batas_jadi_pending_lalu_disetujui(): void
    {
        $km = $this->buatKasMasuk('150000');
        $svc = new VoidApprovalService;

        // Low (maks 50rb) mengajukan void 150rb → PENDING.
        $hasil = $svc->request(['modul' => 'KasMasuk', 'id_record' => $km->kode_transaksi, 'alasan' => 'salah', 'id_pengguna' => $this->low, 'nama' => 'Low']);
        $this->assertSame('pending', $hasil['status']);
        $this->assertSame('aktif', $km->refresh()->status);

        // Admin (tak terbatas) menyetujui → transaksi ter-void.
        $svc->approve($hasil['approval_id'], $this->admin, 'Admin');
        $this->assertSame('void', $km->refresh()->status);
    }

    public function test_void_dalam_batas_langsung_dieksekusi(): void
    {
        $km = $this->buatKasMasuk('30000');
        $svc = new VoidApprovalService;

        $hasil = $svc->request(['modul' => 'KasMasuk', 'id_record' => $km->kode_transaksi, 'alasan' => 'x', 'id_pengguna' => $this->low, 'nama' => 'Low']);
        $this->assertSame('voided', $hasil['status']);
        $this->assertSame('void', $km->refresh()->status);
    }

    public function test_posting_jurnal_umum_di_atas_batas_pending_lalu_diposting(): void
    {
        $svc = new PostingApprovalService;
        $payload = [
            'tanggal' => '2026-07-08', 'keterangan' => 'JU manual',
            'lines' => [
                ['kode_coa' => self::BEBAN, 'debet' => '100000', 'kredit' => '0'],
                ['kode_coa' => self::KAS, 'debet' => '0', 'kredit' => '100000'],
            ],
        ];

        $hasil = $svc->request(['modul' => 'JurnalUmum', 'payload' => $payload, 'id_pengguna' => $this->low, 'nama' => 'Low']);
        $this->assertSame('pending', $hasil['status']);
        $this->assertSame(0, JournalEntry::where('sumber_modul', 'JurnalUmum')->count());

        $svc->approve($hasil['approval_id'], $this->admin, 'Admin');
        $this->assertSame(1, JournalEntry::where('sumber_modul', 'JurnalUmum')->count());
    }
}
