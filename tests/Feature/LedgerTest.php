<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JournalEntry;
use App\Services\Ledger\PostingService;
use App\Services\Ledger\ReversalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Port dari core/ledger/ledger.test.ts — aturan double-entry & integrasi DB.
 */
class LedgerTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZTEST';
    private const ACC_D = 'ZZTEST.001';
    private const ACC_K = 'ZZTEST.002';

    protected function setUp(): void
    {
        parent::setUp();

        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Grup Test']);
        CoaDetail::create(['kode_coa' => self::ACC_D, 'nama_coa' => 'Akun Test Debet', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => self::ACC_K, 'nama_coa' => 'Akun Test Kredit', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
    }

    // ---- validateBalanced (aturan double-entry) ----

    public function test_menerima_jurnal_yang_balance(): void
    {
        $r = PostingService::validateBalanced([
            ['kode_coa' => self::ACC_D, 'debet' => '100000'],
            ['kode_coa' => self::ACC_K, 'kredit' => '100000'],
        ]);
        $this->assertSame('100000.00', $r['totalDebet']);
        $this->assertSame('100000.00', $r['totalKredit']);
    }

    public function test_menolak_jurnal_tidak_balance(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/tidak balance/i');
        PostingService::validateBalanced([
            ['kode_coa' => self::ACC_D, 'debet' => '100000'],
            ['kode_coa' => self::ACC_K, 'kredit' => '90000'],
        ]);
    }

    public function test_menolak_baris_debet_dan_kredit_sekaligus(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/sekaligus/i');
        PostingService::validateBalanced([
            ['kode_coa' => self::ACC_D, 'debet' => '100', 'kredit' => '100'],
            ['kode_coa' => self::ACC_K, 'kredit' => '100'],
        ]);
    }

    public function test_menolak_jurnal_kurang_dari_2_baris(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/minimal 2 baris/i');
        PostingService::validateBalanced([
            ['kode_coa' => self::ACC_D, 'debet' => '100'],
        ]);
    }

    public function test_menjaga_presisi_desimal_tanpa_float(): void
    {
        // 0.1 + 0.2 === 0.3 (BCMath, bukan 0.30000000000000004)
        $r = PostingService::validateBalanced([
            ['kode_coa' => self::ACC_D, 'debet' => '0.1'],
            ['kode_coa' => self::ACC_D, 'debet' => '0.2'],
            ['kode_coa' => self::ACC_K, 'kredit' => '0.3'],
        ]);
        $this->assertSame('0.30', $r['totalDebet']);
    }

    // ---- postJournal + reverseJournalEntry (integrasi DB) ----

    public function test_mem_persist_entry_balance_beserta_barisnya(): void
    {
        $entry = PostingService::postJournal([
            'referensi' => 'ZZ-POST-1',
            'tanggal' => '2026-07-01',
            'sumber_modul' => 'Test',
            'lines' => [
                ['kode_coa' => self::ACC_D, 'debet' => '250000', 'keterangan' => 'debit test'],
                ['kode_coa' => self::ACC_K, 'kredit' => '250000', 'keterangan' => 'credit test'],
            ],
        ]);

        $this->assertSame('aktif', $entry->status);
        $this->assertCount(2, $entry->lines);
        $sumD = $entry->lines->sum(fn ($l) => (float) $l->debet);
        $sumK = $entry->lines->sum(fn ($l) => (float) $l->kredit);
        $this->assertSame($sumD, $sumK);
    }

    public function test_menolak_persist_jurnal_tidak_balance_tanpa_data_tersimpan(): void
    {
        try {
            PostingService::postJournal([
                'referensi' => 'ZZ-POST-BAD',
                'tanggal' => '2026-07-01',
                'sumber_modul' => 'Test',
                'lines' => [
                    ['kode_coa' => self::ACC_D, 'debet' => '100'],
                    ['kode_coa' => self::ACC_K, 'kredit' => '99'],
                ],
            ]);
            $this->fail('Seharusnya melempar AppException.');
        } catch (AppException $e) {
            $this->assertMatchesRegularExpression('/tidak balance/i', $e->getMessage());
        }

        $this->assertNull(JournalEntry::where('referensi', 'ZZ-POST-BAD')->first());
    }

    public function test_void_membuat_entry_pembalik_dan_menandai_asal_void(): void
    {
        $entry = PostingService::postJournal([
            'referensi' => 'ZZ-VOID-1',
            'tanggal' => '2026-07-02',
            'sumber_modul' => 'Test',
            'lines' => [
                ['kode_coa' => self::ACC_D, 'debet' => '500000'],
                ['kode_coa' => self::ACC_K, 'kredit' => '500000'],
            ],
        ]);

        $reversal = ReversalService::reverseJournalEntry($entry->id);
        $this->assertSame($entry->id, $reversal->reversal_of);

        // baris pembalik: debet & kredit tertukar
        $revD = $reversal->lines->firstWhere('kode_coa', self::ACC_D);
        $this->assertSame(500000.0, (float) $revD->kredit);
        $this->assertSame(0.0, (float) $revD->debet);

        $original = JournalEntry::find($entry->id);
        $this->assertSame('void', $original->status);
    }

    public function test_menolak_void_ganda(): void
    {
        $entry = PostingService::postJournal([
            'referensi' => 'ZZ-VOID-2',
            'tanggal' => '2026-07-03',
            'sumber_modul' => 'Test',
            'lines' => [
                ['kode_coa' => self::ACC_D, 'debet' => '1000'],
                ['kode_coa' => self::ACC_K, 'kredit' => '1000'],
            ],
        ]);

        ReversalService::reverseJournalEntry($entry->id);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/sudah di-void/i');
        ReversalService::reverseJournalEntry($entry->id);
    }
}
