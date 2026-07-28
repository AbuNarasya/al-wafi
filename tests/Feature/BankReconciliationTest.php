<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\Level;
use App\Models\User;
use App\Services\Modules\BankReconciliationService;
use App\Services\Modules\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Modul Rekonsiliasi Bank: buat, tandai cleared, penyesuaian, finalisasi. */
class BankReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZBR';
    private const KAS = 'ZZBR.KAS';
    private const LAWAN = 'ZZBR.LAWAN';
    private const BEBAN = 'ZZBR.BEBAN';

    private BankReconciliationService $service;

    private int $uid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BankReconciliationService;

        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'BR Test']);
        CoaDetail::create(['kode_coa' => self::KAS, 'nama_coa' => 'Bank', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => self::LAWAN, 'nama_coa' => 'Pendapatan', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        CoaDetail::create(['kode_coa' => self::BEBAN, 'nama_coa' => 'Beban Admin', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        BankAccount::create(['kode_coa' => self::KAS, 'nama_rekening' => 'Bank Utama', 'jenis_rekening' => 'bank']);
        BusinessUnit::create(['kode_unit' => 'ZZUNIT', 'nama_unit' => 'Unit']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        $this->uid = User::create(['username' => 'admin', 'nama' => 'Admin', 'password_hash' => 'x', 'kode_level' => 'L1'])->id_pengguna;
    }

    private function setor(string $nominal): void
    {
        (new JournalService)->create([
            'tanggal' => '2026-07-10', 'keterangan' => 'Setoran', 'id_pengguna' => $this->uid,
            'lines' => [
                ['kode_coa' => self::KAS, 'debet' => $nominal, 'kredit' => '0'],
                ['kode_coa' => self::LAWAN, 'debet' => '0', 'kredit' => $nominal],
            ],
        ]);
    }

    public function test_create_toggle_cleared_lalu_finalize(): void
    {
        $this->setor('100000');

        $recon = $this->service->create([
            'kode_coa' => self::KAS, 'tanggal' => '2026-07-31', 'saldo_bank' => '100000',
        ], $this->uid);

        $this->assertCount(1, $recon['items']);
        $this->assertSame('100000.00', $recon['saldo_buku']);
        $this->assertSame('100000.00', $recon['selisih']); // item belum cleared

        $after = $this->service->toggleItem($recon['id'], $recon['items'][0]['id'], true);
        $this->assertSame('0.00', $after['selisih']);

        $final = $this->service->finalize($recon['id']);
        $this->assertSame('selesai', $final['status']);
    }

    public function test_penyesuaian_biaya_admin_menyeimbangkan(): void
    {
        $this->setor('100000');

        // Koran hanya 99.000 — ada biaya admin 1.000 yang belum tercatat di buku.
        $recon = $this->service->create([
            'kode_coa' => self::KAS, 'tanggal' => '2026-07-31', 'saldo_bank' => '99000',
        ], $this->uid);

        $this->service->toggleItem($recon['id'], $recon['items'][0]['id'], true);

        $adj = $this->service->adjustment($recon['id'], [
            'arah' => 'kurang', 'nominal' => '1000', 'kode_coa_lawan' => self::BEBAN,
            'keterangan' => 'Biaya admin bank',
        ], $this->uid);

        $this->assertSame('0.00', $adj['selisih']);
        $final = $this->service->finalize($recon['id']);
        $this->assertSame('selesai', $final['status']);
    }
}
