<?php

namespace Tests\Feature;

use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Level;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorType;
use App\Services\Modules\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Modul Invoice: pengakuan hutang + void. */
class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZIN';
    private const BEBAN = 'ZZIN.BEBAN';
    private const HUTANG = 'ZZIN.HUTANG';
    private const UNIT = 'ZZUNIT';

    private InvoiceService $service;

    private int $uid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new InvoiceService;

        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'IN Test']);
        CoaDetail::create(['kode_coa' => self::BEBAN, 'nama_coa' => 'Beban ATK', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => self::HUTANG, 'nama_coa' => 'Hutang Usaha', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit Test']);
        VendorType::create(['kode_jenis_vendor' => 'JV', 'nama' => 'Umum']);
        Vendor::create(['kode_vendor' => 'V001', 'nama_vendor' => 'PT Contoh', 'kode_jenis_vendor' => 'JV']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        $this->uid = User::create(['username' => 'admin', 'nama' => 'Admin', 'password_hash' => 'x', 'kode_level' => 'L1'])->id_pengguna;
    }

    private function buatInvoice(): Invoice
    {
        return $this->service->create([
            'nomor_invoice' => 'INV-VENDOR-001',
            'tanggal_invoice' => '2026-07-10',
            'tanggal_jatuh_tempo' => '2026-08-10',
            'kode_vendor' => 'V001',
            'kode_unit' => self::UNIT,
            'kode_coa_hutang' => self::HUTANG,
            'keterangan' => 'Pembelian ATK',
            'details' => [
                ['kode_coa' => self::BEBAN, 'kuantiti' => '2', 'harga_satuan' => '125000', 'keterangan' => 'ATK'],
            ],
        ], $this->uid);
    }

    public function test_create_pengakuan_hutang_balance(): void
    {
        $inv = $this->buatInvoice();

        $this->assertSame(250000.0, (float) $inv->total);
        $this->assertSame(250000.0, (float) $inv->sisa_hutang);
        $this->assertSame('belum_bayar', $inv->status);
        $this->assertSame('INV-2607-0001', $inv->nomor_ref_internal);

        $entry = JournalEntry::with('lines')
            ->where('sumber_modul', 'Invoice')
            ->where('id_sumber', (string) $inv->id_invoice)
            ->first();
        $this->assertNotNull($entry);
        $beban = $entry->lines->firstWhere('kode_coa', self::BEBAN);
        $hutang = $entry->lines->firstWhere('kode_coa', self::HUTANG);
        $this->assertSame(250000.0, (float) $beban->debet);
        $this->assertSame(250000.0, (float) $hutang->kredit);
    }

    public function test_void_invoice_belum_dibayar(): void
    {
        $inv = $this->buatInvoice();
        $orig = JournalEntry::where('sumber_modul', 'Invoice')->where('id_sumber', (string) $inv->id_invoice)->first();

        $this->service->void($inv->id_invoice, 'salah input', $this->uid);

        $inv->refresh();
        $this->assertSame('void', $inv->status);
        $reversal = JournalEntry::where('reversal_of', $orig->id)->first();
        $this->assertNotNull($reversal);
    }
}
