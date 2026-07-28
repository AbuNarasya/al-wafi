<?php

namespace Tests\Feature;

use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\PurchaseOrder;
use App\Models\Level;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorType;
use App\Services\Modules\InvoiceService;
use App\Services\Modules\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Modul Purchase Order + integrasi progres PO dari Invoice. */
class PurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZPO';
    private const BEBAN = 'ZZPO.BEBAN';
    private const HUTANG = 'ZZPO.HUTANG';
    private const UNIT = 'ZZUNIT';

    private PurchaseOrderService $po;

    private int $uid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->po = new PurchaseOrderService;

        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'PO Test']);
        CoaDetail::create(['kode_coa' => self::BEBAN, 'nama_coa' => 'Beban', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => self::HUTANG, 'nama_coa' => 'Hutang', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit Test']);
        VendorType::create(['kode_jenis_vendor' => 'JV', 'nama' => 'Umum']);
        Vendor::create(['kode_vendor' => 'V001', 'nama_vendor' => 'PT Contoh', 'kode_jenis_vendor' => 'JV']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        $this->uid = User::create(['username' => 'admin', 'nama' => 'Admin', 'password_hash' => 'x', 'kode_level' => 'L1'])->id_pengguna;
    }

    private function buatPo(): PurchaseOrder
    {
        return $this->po->create([
            'tanggal_po' => '2026-07-03',
            'kode_vendor' => 'V001',
            'kode_unit' => self::UNIT,
            'details' => [
                ['kode_coa' => self::BEBAN, 'kuantiti' => '10', 'harga_satuan' => '50000'],
            ],
        ], $this->uid);
    }

    public function test_create_po_nomor_dan_status_open(): void
    {
        $po = $this->buatPo();
        $this->assertSame('PO-2607-0001', $po->nomor_po);
        $this->assertSame('open', $po->status);
        $this->assertSame(500000.0, (float) $po->total_po);
    }

    public function test_cancel_po_belum_diinvoice(): void
    {
        $po = $this->buatPo();
        $this->po->cancel($po->id_po);
        $this->assertSame('batal', $po->refresh()->status);
    }

    public function test_invoice_penuh_menyelesaikan_po(): void
    {
        $po = $this->buatPo();

        (new InvoiceService)->create([
            'nomor_invoice' => 'INV-001',
            'tanggal_invoice' => '2026-07-10',
            'tanggal_jatuh_tempo' => '2026-08-10',
            'kode_vendor' => 'V001',
            'kode_unit' => self::UNIT,
            'kode_coa_hutang' => self::HUTANG,
            'id_po' => $po->id_po,
            'details' => [
                ['kode_coa' => self::BEBAN, 'kuantiti' => '10', 'harga_satuan' => '50000'],
            ],
        ], $this->uid);

        $po->refresh()->load('details');
        $this->assertSame('selesai', $po->status);
        $this->assertSame(10.0, (float) $po->details->first()->qty_invoiced);
    }
}
