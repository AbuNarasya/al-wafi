<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\Bagian;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\CompanySettings;
use App\Models\Inventory;
use App\Models\InvoiceDetail;
use App\Models\JournalLine;
use App\Models\Level;
use App\Models\LokasiPersediaan;
use App\Models\PenerimaanBarang;
use App\Models\PurchaseOrder;
use App\Models\SatuanPersediaan;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorType;
use App\Services\Ledger\InventoryMovement;
use App\Services\Modules\InvoiceService;
use App\Services\Modules\PenerimaanBarangService;
use App\Services\Modules\PurchaseOrderService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WAFI MART TAHAP 1b — PO → PENERIMAAN BARANG → INVOICE.
 *
 * Yang dijaga:
 *  • Stok masuk saat DITERIMA (di lokasinya), berjurnal D Persediaan / K GRNI.
 *  • Invoice yang menagih penerimaan: D GRNI / K Hutang, TANPA menambah stok lagi;
 *    selisih harga → akun Selisih Persediaan barang. GRNI kembali NOL persis.
 *  • Satu baris PO menempuh satu jalur stok — tak bisa diterima DAN ditagih langsung.
 *  • Invoice barang tanpa penerimaan dipaksa ke akun persediaan barangnya.
 *  • Pembatalan & void mengembalikan semuanya ke keadaan semula.
 */
class PenerimaanBarangTest extends TestCase
{
    use RefreshDatabase;

    private const PERSEDIAAN = '1.ZZTB.PRS';

    private const GRNI = '2.ZZTB.GRN';

    private const HUTANG = '2.ZZTB.HUT';

    private const BEBAN = '5.ZZTB.BBN';

    private const SELISIH = '5.ZZTB.SLS';

    private const UNIT = 'ZZTBU';

    private const BAGIAN = 'BZZTB';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        CoaGroup::create(['kode_grup' => 'ZZTB', 'nama_grup' => 'Terima']);
        foreach ([
            [self::PERSEDIAAN, 'Persediaan Toko', 'debet'],
            [self::GRNI, 'Barang Diterima Belum Ditagih', 'kredit'],
            [self::HUTANG, 'Hutang Vendor', 'kredit'],
            [self::BEBAN, 'Beban Lain', 'debet'],
            [self::SELISIH, 'Selisih Persediaan', 'debet'],
        ] as [$k, $n, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => 'ZZTB', 'jenis_saldo' => $s]);
        }
        CompanySettings::create(['id' => 1, 'nama_perusahaan' => 'Uji', 'periode_awal_pembukuan' => '2026-01-01', 'kode_coa_grni' => self::GRNI]);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Wafi Mart']);
        Bagian::create(['kode_bagian' => self::BAGIAN, 'nama_bagian' => 'Koperasi', 'level' => 3]);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        VendorType::create(['kode_jenis_vendor' => 'JZZTB', 'nama' => 'Umum']);
        Vendor::create(['kode_vendor' => 'VZZTB', 'nama_vendor' => 'Distributor Sosro', 'kode_jenis_vendor' => 'JZZTB', 'status' => 'aktif']);
        Vendor::create(['kode_vendor' => 'VZZLAIN', 'nama_vendor' => 'Vendor Lain', 'kode_jenis_vendor' => 'JZZTB', 'status' => 'aktif']);
        LokasiPersediaan::create(['kode' => 'L002', 'nama' => 'Koperasi Banat', 'jenis' => 'toko', 'kode_unit' => self::UNIT, 'status' => 'aktif']);

        Inventory::create([
            'kode_persediaan' => 'BRG0001', 'nama_persediaan' => 'Teh Kotak', 'satuan' => 'pcs',
            'harga_perolehan' => '0', 'kode_coa' => self::PERSEDIAAN,
            'kode_coa_beban' => self::BEBAN, 'kode_coa_selisih' => self::SELISIH, 'status' => 'aktif',
        ]);
        SatuanPersediaan::create(['kode_persediaan' => 'BRG0001', 'satuan' => 'DUS', 'isi' => '24']);

        $this->admin = User::create([
            'username' => 'admtb', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'tim_keuangan' => true,
        ])->fresh();
    }

    // ---- Pembantu ----

    /** PO 96 pcs Teh Kotak @3.750 (akun sengaja SALAH: beban — harus dipaksa ke akun barang). */
    private function po(string $qty = '96'): PurchaseOrder
    {
        return (new PurchaseOrderService)->create([
            'tanggal_po' => '2026-10-01', 'kode_vendor' => 'VZZTB', 'kode_unit' => self::UNIT,
            'details' => [['kode_coa' => self::BEBAN, 'kode_persediaan' => 'BRG0001', 'kuantiti' => $qty, 'harga_satuan' => '3750']],
        ], $this->admin->id_pengguna);
    }

    private function terima(?PurchaseOrder $po, array $baris, string $lokasi = 'L002'): PenerimaanBarang
    {
        return (new PenerimaanBarangService)->create([
            'tanggal' => '2026-10-03', 'id_po' => $po?->id_po, 'kode_vendor' => 'VZZTB',
            'kode_lokasi' => $lokasi, 'nomor_surat_jalan' => 'SJ-77', 'details' => $baris,
        ], $this->admin->id_pengguna);
    }

    private function invoice(array $baris, array $header = []): \App\Models\Invoice
    {
        return (new InvoiceService)->create([
            'nomor_invoice' => 'FAK-'.uniqid(), 'tanggal_invoice' => '2026-10-05', 'tanggal_jatuh_tempo' => '2026-11-05',
            'kode_vendor' => 'VZZTB', 'kode_unit' => self::UNIT, 'kode_coa_hutang' => self::HUTANG,
            'details' => $baris, ...$header,
        ], $this->admin->id_pengguna);
    }

    /** Saldo bersih (debet − kredit) seluruh baris jurnal, termasuk pembaliknya. */
    private function saldo(string $coa): string
    {
        $l = JournalLine::where('kode_coa', $coa)->selectRaw('COALESCE(SUM(debet),0) d, COALESCE(SUM(kredit),0) k')->first();

        return Money::sub($l->d, $l->k);
    }

    // ---- Penerimaan ----

    public function test_po_barang_memakai_akun_persediaan_barangnya(): void
    {
        $po = $this->po();
        $this->assertSame([self::PERSEDIAAN, 'BRG0001'], [$po->details[0]->kode_coa, $po->details[0]->kode_persediaan]);
    }

    public function test_terima_dalam_dus_menambah_stok_satuan_dasar_di_lokasinya(): void
    {
        $po = $this->po();
        $doc = $this->terima($po, [[
            'id_po_detail' => $po->details[0]->id, 'kode_persediaan' => 'BRG0001',
            'satuan' => 'DUS', 'kuantiti' => '3', 'harga_satuan' => '90000',
        ]]);

        $this->assertSame('TB-2610-0001', $doc->nomor);
        $this->assertSame(['72.0000', '270000.00', '24.0000'], [$doc->details[0]->kuantiti, $doc->details[0]->total, $doc->details[0]->isi]);
        $this->assertSame('72.0000', InventoryMovement::stok('BRG0001', 'L002'));
        $this->assertSame('0.0000', InventoryMovement::stok('BRG0001', 'L001'));

        // D Persediaan / K GRNI — hutang BELUM ada.
        $this->assertSame('270000.00', $this->saldo(self::PERSEDIAAN));
        $this->assertSame('-270000.00', $this->saldo(self::GRNI));
        $this->assertSame('0.00', $this->saldo(self::HUTANG));
        // Jurnalnya ikut unit lokasi/PO.
        $this->assertSame([self::UNIT], JournalLine::where('entry_id', $doc->journal_entry_id)->where('kode_coa', self::PERSEDIAAN)->pluck('kode_unit')->all());

        $this->assertSame('72.0000', $po->details[0]->fresh()->qty_diterima);
    }

    public function test_terima_melebihi_sisa_po_ditolak(): void
    {
        $po = $this->po('48');
        $this->terima($po, [['id_po_detail' => $po->details[0]->id, 'kode_persediaan' => 'BRG0001', 'satuan' => 'DUS', 'kuantiti' => '1', 'harga_satuan' => '90000']]);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/melebihi sisa PO \(sisa 24 pcs\)/');
        $this->terima($po, [['id_po_detail' => $po->details[0]->id, 'kode_persediaan' => 'BRG0001', 'kuantiti' => '25', 'harga_satuan' => '3750']]);
    }

    public function test_akun_grni_wajib_ditetapkan(): void
    {
        CompanySettings::whereKey(1)->update(['kode_coa_grni' => null]);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/Barang Diterima Belum Ditagih" belum ditetapkan/');
        $this->terima(null, [['kode_persediaan' => 'BRG0001', 'kuantiti' => '1', 'harga_satuan' => '1000']]);
    }

    // ---- Invoice yang menagih penerimaan ----

    public function test_invoice_dari_penerimaan_mengakui_hutang_tanpa_menambah_stok(): void
    {
        $po = $this->po();
        $doc = $this->terima($po, [['id_po_detail' => $po->details[0]->id, 'kode_persediaan' => 'BRG0001', 'kuantiti' => '96', 'harga_satuan' => '3750']]);
        $d = $doc->details[0];

        $inv = $this->invoice([['kode_coa' => self::GRNI, 'kuantiti' => '96', 'harga_satuan' => '3750', 'id_penerimaan_detail' => $d->id]]);

        $this->assertSame('96.0000', InventoryMovement::stok('BRG0001'));   // tetap 96, bukan 192
        $this->assertSame('0.00', $this->saldo(self::GRNI));
        $this->assertSame('-360000.00', $this->saldo(self::HUTANG));
        $this->assertSame(['96.0000', '360000.00'], [$d->fresh()->qty_ditagih, $d->fresh()->nilai_ditagih]);

        // Invoice tanpa PO mewarisi PO penerimaannya, dan PO-nya selesai.
        $this->assertSame($po->id_po, $inv->id_po);
        $this->assertSame('selesai', $po->fresh()->status);
    }

    public function test_selisih_harga_invoice_ke_akun_selisih_persediaan(): void
    {
        $doc = $this->terima(null, [['kode_persediaan' => 'BRG0001', 'kuantiti' => '10', 'harga_satuan' => '3750']]);
        $d = $doc->details[0];

        // Tanpa Bagian → ditolak dengan pesan yang menyebut selisihnya.
        try {
            $this->invoice([['kode_coa' => self::GRNI, 'kuantiti' => '10', 'harga_satuan' => '3800', 'id_penerimaan_detail' => $d->id]]);
            $this->fail('Selisih tanpa Bagian seharusnya ditolak.');
        } catch (AppException $e) {
            $this->assertStringContainsString('selisih Rp 500', $e->getMessage());
        }

        $this->invoice([['kode_coa' => self::GRNI, 'kuantiti' => '10', 'harga_satuan' => '3800', 'kode_bagian' => self::BAGIAN, 'id_penerimaan_detail' => $d->id]]);

        $this->assertSame('0.00', $this->saldo(self::GRNI));
        $this->assertSame('500.00', $this->saldo(self::SELISIH));
        $this->assertSame('-38000.00', $this->saldo(self::HUTANG));
        // Nilai persediaan TIDAK berubah oleh invoice — buku pembantu tetap cocok.
        $this->assertSame('37500.00', Inventory::find('BRG0001')->nilai_persediaan);
        $this->assertSame('37500.00', $this->saldo(self::PERSEDIAAN));
    }

    public function test_tagihan_bertahap_menghabiskan_nilai_terima_tanpa_sisa_pembulatan(): void
    {
        // 1 DUS isi 3 pcs @10.000 → nilai per pcs 3.333,33… yang tak habis dibagi.
        SatuanPersediaan::create(['kode_persediaan' => 'BRG0001', 'satuan' => 'PAK', 'isi' => '3']);
        $d = $this->terima(null, [['kode_persediaan' => 'BRG0001', 'satuan' => 'PAK', 'kuantiti' => '1', 'harga_satuan' => '10000']])->details[0];

        $this->invoice([['kode_coa' => self::GRNI, 'kuantiti' => '1', 'harga_satuan' => '3333.33', 'kode_bagian' => self::BAGIAN, 'id_penerimaan_detail' => $d->id]]);
        $this->invoice([['kode_coa' => self::GRNI, 'kuantiti' => '2', 'harga_satuan' => '3333.335', 'kode_bagian' => self::BAGIAN, 'id_penerimaan_detail' => $d->id]]);

        $this->assertSame('0.00', $this->saldo(self::GRNI));
        $this->assertSame(['3.0000', '10000.00'], [$d->fresh()->qty_ditagih, $d->fresh()->nilai_ditagih]);
    }

    public function test_tagih_seluruh_penerimaan_dus_beda_pembulatan_bukan_selisih(): void
    {
        // Audit 21 Okt 2026: 3 DUS isi 24 @50.000 = 72 pcs Rp 150.000; invoice 72 × 2.083,33 = 149.999,76 dulu
        // dianggap "selisih Rp 0,24" yang memaksa memilih Bagian. Kini total baris disamakan dengan nilai terima.
        SatuanPersediaan::create(['kode_persediaan' => 'BRG0001', 'satuan' => 'KRT', 'isi' => '24']);
        $d = $this->terima(null, [['kode_persediaan' => 'BRG0001', 'satuan' => 'KRT', 'kuantiti' => '3', 'harga_satuan' => '50000']])->details[0];

        $inv = $this->invoice([['kode_coa' => self::GRNI, 'kuantiti' => '72', 'harga_satuan' => '2083.33', 'id_penerimaan_detail' => $d->id]]);

        $this->assertSame('150000.00', Money::of($inv->total));
        $this->assertSame('0.00', $this->saldo(self::GRNI));
    }

    public function test_invoice_langsung_atas_barang_yang_sudah_diterima_ditolak(): void
    {
        // Audit 21 Okt 2026: stok masuk dua kali & GRNI tak pernah nol.
        $this->terima(null, [['kode_persediaan' => 'BRG0001', 'satuan' => 'pcs', 'kuantiti' => '10', 'harga_satuan' => '3750']]);
        try {
            $this->invoice([['kode_coa' => self::PERSEDIAAN, 'kode_persediaan' => 'BRG0001', 'kuantiti' => '10', 'harga_satuan' => '3750']], ['kode_lokasi' => 'L002']);
            $this->fail('Harus ditolak.');
        } catch (AppException $e) {
            $this->assertStringContainsString('Muat dari Penerimaan', $e->getMessage());
        }
    }

    public function test_tagih_melebihi_yang_diterima_atau_vendor_lain_ditolak(): void
    {
        $d = $this->terima(null, [['kode_persediaan' => 'BRG0001', 'kuantiti' => '10', 'harga_satuan' => '3750']])->details[0];

        try {
            $this->invoice([['kode_coa' => self::GRNI, 'kuantiti' => '11', 'harga_satuan' => '3750', 'id_penerimaan_detail' => $d->id]]);
            $this->fail('Melebihi yang diterima seharusnya ditolak.');
        } catch (AppException $e) {
            $this->assertStringContainsString('melebihi yang diterima', $e->getMessage());
        }

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/milik vendor lain/');
        $this->invoice([['kode_coa' => self::GRNI, 'kuantiti' => '1', 'harga_satuan' => '3750', 'id_penerimaan_detail' => $d->id]], ['kode_vendor' => 'VZZLAIN']);
    }

    // ---- Satu jalur stok per baris PO ----

    public function test_baris_po_yang_sudah_diterima_tak_bisa_ditagih_langsung(): void
    {
        $po = $this->po();
        $this->terima($po, [['id_po_detail' => $po->details[0]->id, 'kode_persediaan' => 'BRG0001', 'kuantiti' => '10', 'harga_satuan' => '3750']]);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/sudah diterima lewat Penerimaan Barang/');
        $this->invoice([['kode_coa' => self::PERSEDIAAN, 'kode_persediaan' => 'BRG0001', 'kuantiti' => '10', 'harga_satuan' => '3750', 'id_po_detail' => $po->details[0]->id]], ['id_po' => $po->id_po]);
    }

    public function test_baris_po_yang_sudah_ditagih_langsung_tak_bisa_diterima(): void
    {
        $po = $this->po();
        $this->invoice([['kode_coa' => self::PERSEDIAAN, 'kode_persediaan' => 'BRG0001', 'kuantiti' => '10', 'harga_satuan' => '3750', 'id_po_detail' => $po->details[0]->id]], ['id_po' => $po->id_po]);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/sudah ditagih langsung/');
        $this->terima($po, [['id_po_detail' => $po->details[0]->id, 'kode_persediaan' => 'BRG0001', 'kuantiti' => '10', 'harga_satuan' => '3750']]);
    }

    public function test_invoice_langsung_dipaksa_ke_akun_barang_dan_lokasi_pilihan(): void
    {
        $inv = $this->invoice(
            [['kode_coa' => self::BEBAN, 'kode_persediaan' => 'BRG0001', 'kuantiti' => '5', 'harga_satuan' => '4000', 'kode_bagian' => self::BAGIAN]],
            ['kode_lokasi' => 'L002'],
        );

        $this->assertSame(self::PERSEDIAAN, $inv->details[0]->kode_coa);
        $this->assertSame('20000.00', $this->saldo(self::PERSEDIAAN));
        $this->assertSame('0.00', $this->saldo(self::BEBAN));
        $this->assertSame('5.0000', InventoryMovement::stok('BRG0001', 'L002'));
    }

    public function test_invoice_vendor_berbeda_dari_po_ditolak(): void
    {
        $po = $this->po();
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/berbeda dari vendor PO/');
        $this->invoice([['kode_coa' => self::BEBAN, 'kuantiti' => '1', 'harga_satuan' => '1000', 'kode_bagian' => self::BAGIAN]], ['id_po' => $po->id_po, 'kode_vendor' => 'VZZLAIN']);
    }

    // ---- Void & batal ----

    public function test_void_invoice_lalu_batal_penerimaan_memulihkan_semuanya(): void
    {
        $po = $this->po();
        $doc = $this->terima($po, [['id_po_detail' => $po->details[0]->id, 'kode_persediaan' => 'BRG0001', 'kuantiti' => '20', 'harga_satuan' => '3750']]);
        $inv = $this->invoice([['kode_coa' => self::GRNI, 'kuantiti' => '20', 'harga_satuan' => '3750', 'id_penerimaan_detail' => $doc->details[0]->id]]);

        // Sudah ditagih → penerimaan terkunci.
        try {
            (new PenerimaanBarangService)->batal($doc->id_penerimaan, 'salah input', $this->admin->id_pengguna);
            $this->fail('Penerimaan yang sudah ditagih seharusnya tak bisa dibatalkan.');
        } catch (AppException $e) {
            $this->assertStringContainsString('Void invoice-nya dulu', $e->getMessage());
        }

        (new InvoiceService)->void($inv->id_invoice, 'salah', $this->admin->id_pengguna);
        $this->assertSame(['0.0000', '0.00'], [$doc->details[0]->fresh()->qty_ditagih, $doc->details[0]->fresh()->nilai_ditagih]);
        $this->assertSame('-75000.00', $this->saldo(self::GRNI));
        $this->assertSame('20.0000', InventoryMovement::stok('BRG0001'));   // void invoice tak menyentuh stok
        $this->assertSame('0.0000', $po->details[0]->fresh()->qty_invoiced);

        (new PenerimaanBarangService)->batal($doc->id_penerimaan, 'salah input', $this->admin->id_pengguna);
        $this->assertSame('batal', $doc->fresh()->status);
        $this->assertSame('0.0000', InventoryMovement::stok('BRG0001'));
        $this->assertSame('0.00', $this->saldo(self::GRNI));
        $this->assertSame('0.00', $this->saldo(self::PERSEDIAAN));
        $this->assertSame('0.0000', $po->details[0]->fresh()->qty_diterima);
    }

    public function test_batal_penerimaan_ditolak_bila_barangnya_sudah_keluar(): void
    {
        $doc = $this->terima(null, [['kode_persediaan' => 'BRG0001', 'kuantiti' => '10', 'harga_satuan' => '3750']]);
        InventoryMovement::keluar(['kode_persediaan' => 'BRG0001', 'kode_lokasi' => 'L002', 'kuantiti' => '4', 'sumber_modul' => 'Persediaan', 'sumber_ref' => 'MP-1']);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/sudah terlanjur dipakai/');
        (new PenerimaanBarangService)->batal($doc->id_penerimaan, 'salah', $this->admin->id_pengguna);
    }

    public function test_po_yang_barangnya_sudah_diterima_tak_bisa_dibatalkan(): void
    {
        $po = $this->po();
        $this->terima($po, [['id_po_detail' => $po->details[0]->id, 'kode_persediaan' => 'BRG0001', 'kuantiti' => '1', 'harga_satuan' => '3750']]);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/sudah diterima gudang/');
        (new PurchaseOrderService)->cancel($po->id_po);
    }

    // ---- Layar ----

    public function test_alur_layar_po_terima_invoice(): void
    {
        // PO lewat form: barang dipilih, akun ikut barang.
        $this->actingAs($this->admin)->post(route('purchase_orders.store'), [
            'tanggal_po' => '2026-10-01', 'kode_vendor' => 'VZZTB', 'kode_unit' => self::UNIT,
            'details' => [['kode_persediaan' => 'BRG0001', 'kode_coa' => self::PERSEDIAAN, 'kuantiti' => '48', 'harga_satuan' => '3750']],
        ])->assertRedirect();
        $po = PurchaseOrder::with('details')->sole();
        $this->assertSame('BRG0001', $po->details[0]->kode_persediaan);

        $this->actingAs($this->admin)->get(route('purchase_orders.show', $po->id_po))->assertOk()->assertSee('Terima Barang');
        // Data baris ada di JSON Alpine (@js meng-escape kutip menjadi ").
        $html = str_replace('\\u0022', '"', $this->actingAs($this->admin)->get(route('penerimaan_barang.create', ['po' => $po->id_po]))->assertOk()->getContent());
        $this->assertStringContainsString('"id_po_detail":'.$po->details[0]->id, $html);
        $this->assertStringContainsString('Teh Kotak (pcs)', $html);

        // Baris berjumlah 0 = belum datang, dibuang.
        $this->actingAs($this->admin)->post(route('penerimaan_barang.store'), [
            'tanggal' => '2026-10-03', 'id_po' => $po->id_po, 'kode_vendor' => 'VZZTB', 'kode_lokasi' => 'L002',
            'details' => [
                ['id_po_detail' => $po->details[0]->id, 'kode_persediaan' => 'BRG0001', 'satuan' => 'DUS', 'kuantiti' => '2', 'harga_satuan' => '90000'],
                ['id_po_detail' => '', 'kode_persediaan' => 'BRG0001', 'satuan' => '', 'kuantiti' => '0', 'harga_satuan' => '1'],
            ],
        ])->assertRedirect()->assertSessionHas('status', fn ($p) => str_contains($p, 'Koperasi Banat'));

        $doc = PenerimaanBarang::with('details')->sole();
        $this->actingAs($this->admin)->get(route('penerimaan_barang.show', $doc->id_penerimaan))->assertOk()->assertSee('2 DUS')->assertSee('= 48 pcs');
        $this->actingAs($this->admin)->get(route('penerimaan_barang.index'))->assertOk()->assertSee($doc->nomor)->assertSee('Belum ditagih');

        // Form invoice menawarkan penerimaan itu.
        $this->actingAs($this->admin)->get(route('invoices.create'))->assertOk()->assertSee($doc->nomor);

        $this->actingAs($this->admin)->post(route('invoices.store'), [
            'nomor_invoice' => 'FAK-1', 'tanggal_invoice' => '2026-10-05', 'tanggal_jatuh_tempo' => '2026-11-05',
            'kode_vendor' => 'VZZTB', 'kode_unit' => self::UNIT, 'kode_coa_hutang' => self::HUTANG,
            'details' => [['kode_coa' => self::GRNI, 'kode_persediaan' => 'BRG0001', 'kuantiti' => '48', 'harga_satuan' => '3750', 'id_penerimaan_detail' => $doc->details[0]->id]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, InvoiceDetail::whereNotNull('id_penerimaan_detail')->count());
        $this->assertSame('0.00', $this->saldo(self::GRNI));
        $this->assertSame('selesai', $po->fresh()->status);
    }

    public function test_akun_grni_diatur_di_pengaturan_perusahaan(): void
    {
        $this->actingAs($this->admin)->get(route('company_settings.edit'))->assertOk()->assertSee('Akun Barang Diterima Belum Ditagih');

        $this->actingAs($this->admin)->put(route('company_settings.update'), [
            'nama_perusahaan' => 'Uji', 'jenis_perusahaan' => 'Yayasan', 'mata_uang' => 'IDR',
            'periode_awal_pembukuan' => '2026-01-01', 'bulan_awal_anggaran' => 1, 'kode_coa_grni' => self::HUTANG,
        ])->assertRedirect();

        $this->assertSame(self::HUTANG, CompanySettings::find(1)->kode_coa_grni);
    }
}
