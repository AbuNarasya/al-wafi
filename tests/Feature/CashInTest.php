<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\CashIn;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\BusinessUnit;
use App\Models\JournalEntry;
use App\Models\Level;
use App\Models\User;
use App\Services\Modules\CashInService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Port bagian Kas Masuk dari modules/cash-in/cash.test.ts. */
class CashInTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZTX';
    private const BANK = 'ZZTX.BANK';
    private const PEND = 'ZZTX.PEND';
    private const UNIT = 'ZZUNIT';

    private CashInService $service;

    private int $uid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new CashInService;

        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'TX Test']);
        CoaDetail::create(['kode_coa' => self::BANK, 'nama_coa' => 'Kas Test', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => self::PEND, 'nama_coa' => 'Pendapatan Test', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        BankAccount::create(['kode_coa' => self::BANK, 'nama_rekening' => 'Kas Test', 'jenis_rekening' => 'tunai']);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit Test']);

        // User + level (level max_transaksi null = tak terbatas, agar void lolos otorisasi).
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        // id_pengguna aktual dipakai (sequence Postgres tak di-rollback antar-test).
        $this->uid = User::create(['username' => 'admin', 'nama' => 'Admin', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => true])->id_pengguna;
    }

    public function test_kas_masuk_debit_bank_kredit_rincian_balance(): void
    {
        $rec = $this->service->create([
            'tanggal' => '2026-07-05',
            'kode_unit' => self::UNIT,
            'kode_rekening' => self::BANK,
            'keterangan' => 'Test kas masuk',
            'details' => [['kode_coa' => self::PEND, 'nominal' => '150000']],
        ], $this->uid);

        $this->assertSame(150000.0, (float) $rec->nominal);
        $this->assertSame('aktif', $rec->status);

        $entry = JournalEntry::with('lines')
            ->where('sumber_modul', 'KasMasuk')
            ->where('id_sumber', (string) $rec->kode_transaksi)
            ->first();
        $this->assertNotNull($entry);

        $bankLine = $entry->lines->firstWhere('kode_coa', self::BANK);
        $pendLine = $entry->lines->firstWhere('kode_coa', self::PEND);
        $this->assertSame(150000.0, (float) $bankLine->debet);
        $this->assertSame(150000.0, (float) $pendLine->kredit);

        $sumD = $entry->lines->sum(fn ($l) => (float) $l->debet);
        $sumK = $entry->lines->sum(fn ($l) => (float) $l->kredit);
        $this->assertSame($sumD, $sumK);
    }

    public function test_void_kas_masuk_reversal_dan_status_void(): void
    {
        $rec = $this->service->create([
            'tanggal' => '2026-07-07',
            'kode_unit' => self::UNIT,
            'kode_rekening' => self::BANK,
            'keterangan' => 'Test void',
            'details' => [['kode_coa' => self::PEND, 'nominal' => '50000']],
        ], $this->uid);

        $orig = JournalEntry::where('sumber_modul', 'KasMasuk')
            ->where('id_sumber', (string) $rec->kode_transaksi)
            ->first();

        $this->service->void($rec->kode_transaksi, ['alasan' => 'test'], $this->uid, 'admin');

        $voided = CashIn::find($rec->kode_transaksi);
        $this->assertSame('void', $voided->status);

        $reversal = JournalEntry::where('reversal_of', $orig->id)->first();
        $this->assertNotNull($reversal);
    }

    public function test_doc_number_berurutan_per_bulan(): void
    {
        $r1 = $this->service->create([
            'tanggal' => '2026-07-05', 'kode_unit' => self::UNIT, 'kode_rekening' => self::BANK,
            'keterangan' => 'A', 'details' => [['kode_coa' => self::PEND, 'nominal' => '1000']],
        ], $this->uid);
        $r2 = $this->service->create([
            'tanggal' => '2026-07-09', 'kode_unit' => self::UNIT, 'kode_rekening' => self::BANK,
            'keterangan' => 'B', 'details' => [['kode_coa' => self::PEND, 'nominal' => '2000']],
        ], $this->uid);

        $this->assertSame('KM-2607-0001', $r1->nomor_transaksi);
        $this->assertSame('KM-2607-0002', $r2->nomor_transaksi);
    }
}
