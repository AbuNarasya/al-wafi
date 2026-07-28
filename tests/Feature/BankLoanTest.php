<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\BankLoan;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JournalEntry;
use App\Services\Modules\BankLoanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Modul Pinjaman Bank: pencairan, angsuran, void. */
class BankLoanTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZBL';
    private const KAS = 'ZZBL.KAS';
    private const HUTANG = 'ZZBL.HUTANG';

    private BankLoanService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BankLoanService;

        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'BL Test']);
        CoaDetail::create(['kode_coa' => self::KAS, 'nama_coa' => 'Kas', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => self::HUTANG, 'nama_coa' => 'Hutang Bank', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        BankAccount::create(['kode_coa' => self::KAS, 'nama_rekening' => 'Kas', 'jenis_rekening' => 'bank']);
    }

    private function buatPinjaman(bool $posting = true): BankLoan
    {
        return $this->service->create([
            'nama_bank' => 'Bank Syariah',
            'jenis_akad' => 'murabahah',
            'pokok_awal' => '10000000',
            'tanggal_mulai' => '2026-07-01',
            'kode_coa_hutang' => self::HUTANG,
            'kode_rekening' => self::KAS,
            'posting_pencairan' => $posting,
        ], null);
    }

    public function test_pencairan_posting_jurnal_balance_dan_sisa_pokok(): void
    {
        $loan = $this->buatPinjaman();

        $this->assertSame('10000000.00', $loan->sisa_pokok);
        $this->assertSame('aktif', $loan->status);

        $entry = JournalEntry::with('lines')
            ->where('sumber_modul', 'PinjamanBank')
            ->where('id_sumber', (string) $loan->id)
            ->first();
        $this->assertNotNull($entry);
        $kas = $entry->lines->firstWhere('kode_coa', self::KAS);
        $hutang = $entry->lines->firstWhere('kode_coa', self::HUTANG);
        $this->assertSame(10000000.0, (float) $kas->debet);
        $this->assertSame(10000000.0, (float) $hutang->kredit);
    }

    public function test_apply_payment_menaikkan_pokok_terbayar_dan_lunas(): void
    {
        $loan = $this->buatPinjaman(false);

        $this->service->applyPayment($loan->id, '4000000');
        $loan->refresh();
        $this->assertSame('6000000.00', $loan->sisa_pokok);
        $this->assertSame('aktif', $loan->status);

        $this->service->applyPayment($loan->id, '6000000');
        $loan->refresh();
        $this->assertSame('0.00', $loan->sisa_pokok);
        $this->assertSame('lunas', $loan->status);
    }

    public function test_apply_payment_melebihi_sisa_ditolak(): void
    {
        $loan = $this->buatPinjaman(false);
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/melebihi sisa pokok/i');
        $this->service->applyPayment($loan->id, '11000000');
    }

    public function test_void_pinjaman_belum_diangsur(): void
    {
        $loan = $this->buatPinjaman();
        $this->service->void($loan->id, 'salah input', null, 'admin');

        $loan->refresh();
        $this->assertSame('void', $loan->status);
        $reversal = JournalEntry::where('reversal_of', function ($q) use ($loan) {
            $q->select('id')->from('journal_entries')
                ->where('sumber_modul', 'PinjamanBank')->where('id_sumber', (string) $loan->id)
                ->where('status', 'void')->limit(1);
        })->first();
        $this->assertNotNull($reversal);
    }

    public function test_void_pinjaman_sudah_diangsur_ditolak(): void
    {
        $loan = $this->buatPinjaman(false);
        $this->service->applyPayment($loan->id, '1000000');

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/sudah diangsur/i');
        $this->service->void($loan->id, 'x', null, 'admin');
    }
}
