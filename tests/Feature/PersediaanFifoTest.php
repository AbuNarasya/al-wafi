<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\Bagian;
use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\Inventory;
use App\Models\JournalLine;
use App\Models\LapisanPersediaan;
use App\Models\Level;
use App\Models\MutasiPersediaan;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorType;
use App\Services\Ledger\InventoryMovement;
use App\Services\Modules\InvoiceService;
use App\Services\Modules\JournalService;
use App\Services\Modules\PersediaanService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PERSEDIAAN FIFO + KARTU STOK.
 *
 * Empat cacat lama yang harus tertutup dan tidak boleh kembali:
 *  1. Harga rata-rata tertimbang RUSAK PERMANEN saat transaksi di-void —
 *     kuantitinya dikembalikan, harganya tidak.
 *  2. Invoice mencocokkan barang lewat `kode_coa`, yang lazim dipakai BANYAK
 *     barang, sehingga stok bertambah pada barang yang salah.
 *  3. Mutasi stok manual sama sekali tidak berjurnal.
 *  4. Tak ada riwayat pergerakan — "stok sekian ini dari mana" tak terjawab.
 */
class PersediaanFifoTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZFF';

    private const PERSEDIAAN = '1.ZZFF.PRS';

    private const KAS = '1.ZZFF.KAS';

    private const HUTANG = '2.ZZFF.HUT';

    private const BEBAN = '5.ZZFF.BBN';

    private const SELISIH = '5.ZZFF.SLS';

    private const UNIT = 'ZZFFU';

    private const BAGIAN = 'BZZFF';

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();

        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'FIFO']);
        foreach ([
            [self::PERSEDIAAN, 'Persediaan', 'debet'],
            [self::KAS, 'Kas', 'debet'],
            [self::HUTANG, 'Hutang Usaha', 'kredit'],
            [self::BEBAN, 'Beban Pemakaian', 'debet'],
            [self::SELISIH, 'Selisih Persediaan', 'debet'],
        ] as [$k, $n, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => self::GRP, 'jenis_saldo' => $s]);
        }

        BankAccount::create(['kode_coa' => self::KAS, 'nama_rekening' => 'Kas Uji', 'jenis_rekening' => 'tunai', 'status' => 'aktif']);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit Uji']);
        Bagian::create(['kode_bagian' => self::BAGIAN, 'nama_bagian' => 'Dapur', 'level' => 3]);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        VendorType::create(['kode_jenis_vendor' => 'JZZFF', 'nama' => 'Umum']);
        Vendor::create(['kode_vendor' => 'VZZFF', 'nama_vendor' => 'Vendor Uji', 'kode_jenis_vendor' => 'JZZFF', 'status' => 'aktif']);

        $this->admin = User::create([
            'username' => 'admff', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'tim_keuangan' => true,
        ])->id_pengguna;
    }

    private function barang(string $kode, string $nama = 'Beras'): Inventory
    {
        return Inventory::create([
            'kode_persediaan' => $kode, 'nama_persediaan' => $nama, 'satuan' => 'kg',
            'harga_perolehan' => '0', 'kode_coa' => self::PERSEDIAAN,
            'kode_coa_beban' => self::BEBAN, 'kode_coa_selisih' => self::SELISIH, 'status' => 'aktif',
        ]);
    }

    private function beli(string $kode, string $qty, string $nilai, string $tanggal, string $ref = 'UJI-1'): MutasiPersediaan
    {
        return InventoryMovement::masuk([
            'kode_persediaan' => $kode, 'kuantiti' => $qty, 'nilai' => $nilai,
            'tanggal' => $tanggal, 'sumber_modul' => 'JurnalUmum', 'sumber_ref' => $ref,
            'id_pengguna' => $this->admin,
        ]);
    }

    // ---- FIFO ----

    public function test_pengeluaran_menggerus_lapisan_tertua_lebih_dulu(): void
    {
        $this->barang('BRG0001');
        $this->beli('BRG0001', '100', '1000000', '2026-07-01', 'BELI-1');  // @10.000
        $this->beli('BRG0001', '100', '1500000', '2026-08-01', 'BELI-2');  // @15.000

        // Ambil 150: 100 dari lapisan @10.000 + 50 dari lapisan @15.000.
        $keluar = InventoryMovement::keluar([
            'kode_persediaan' => 'BRG0001', 'kuantiti' => '150',
            'tanggal' => '2026-09-01', 'sumber_modul' => 'Persediaan', 'sumber_ref' => 'MP-1',
        ]);

        // 100×10.000 + 50×15.000 = 1.750.000 (rata-rata tertimbang akan memberi
        // 150×12.500 = 1.875.000 — angka itulah yang harus TIDAK muncul).
        $this->assertSame('1750000.00', $keluar->nilai);
        $this->assertSame('50.0000', $keluar->saldo_kuantiti);
        $this->assertSame('750000.00', $keluar->saldo_nilai);

        // Lapisan tertua habis, lapisan kedua tersisa 50.
        $sisa = LapisanPersediaan::where('kode_persediaan', 'BRG0001')->where('kuantiti_sisa', '>', 0)->get();
        $this->assertCount(1, $sisa);
        $this->assertSame('15000.00', $sisa->first()->harga_satuan);
    }

    public function test_stok_tak_cukup_ditolak(): void
    {
        $this->barang('BRG0001');
        $this->beli('BRG0001', '10', '100000', '2026-07-01');

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/tidak cukup/');
        InventoryMovement::keluar([
            'kode_persediaan' => 'BRG0001', 'kuantiti' => '11',
            'tanggal' => '2026-07-02', 'sumber_modul' => 'Persediaan',
        ]);
    }

    // ---- Kartu stok ----

    public function test_kartu_stok_mencatat_tiap_pergerakan_dengan_saldo_berjalan(): void
    {
        $this->barang('BRG0001');
        $this->beli('BRG0001', '100', '1000000', '2026-07-01', 'BELI-1');
        $this->beli('BRG0001', '50', '600000', '2026-07-05', 'BELI-2');
        InventoryMovement::keluar([
            'kode_persediaan' => 'BRG0001', 'kuantiti' => '30',
            'tanggal' => '2026-07-10', 'sumber_modul' => 'Persediaan', 'sumber_ref' => 'MP-1',
        ]);

        $kartu = MutasiPersediaan::where('kode_persediaan', 'BRG0001')->orderBy('id')->get();

        $this->assertCount(3, $kartu);
        $this->assertSame(['masuk', 'masuk', 'keluar'], $kartu->pluck('arah')->all());
        $this->assertSame(['100.0000', '150.0000', '120.0000'], $kartu->pluck('saldo_kuantiti')->all());
        // Saldo nilai: 1.000.000 → 1.600.000 → 1.600.000 − (30×10.000) = 1.300.000
        $this->assertSame('1300000.00', $kartu->last()->saldo_nilai);
    }

    // ---- Pembatalan ----

    public function test_pembatalan_pembelian_menarik_lapisannya_tanpa_merusak_harga(): void
    {
        $this->barang('BRG0001');
        $this->beli('BRG0001', '100', '1000000', '2026-07-01', 'BELI-1');  // @10.000
        $this->beli('BRG0001', '100', '2000000', '2026-08-01', 'BELI-2');  // @20.000

        InventoryMovement::batalkanDokumen('JurnalUmum', 'BELI-2', $this->admin);

        $item = Inventory::find('BRG0001');
        // Tersisa persis lapisan pertama: 100 @10.000. Dulu pembatalan hanya
        // mengurangi kuantiti dan meninggalkan harga rata-rata 15.000 selamanya.
        $this->assertSame('100.0000', Money::of(Money::sub($item->stok_masuk, $item->stok_keluar), 4));
        $this->assertSame('1000000.00', $item->nilai_persediaan);
        $this->assertSame('10000.00', $item->harga_perolehan);
    }

    public function test_pembatalan_pembelian_ditolak_bila_barangnya_sudah_dipakai(): void
    {
        $this->barang('BRG0001');
        $this->beli('BRG0001', '100', '1000000', '2026-07-01', 'BELI-1');
        InventoryMovement::keluar([
            'kode_persediaan' => 'BRG0001', 'kuantiti' => '40',
            'tanggal' => '2026-07-10', 'sumber_modul' => 'Persediaan', 'sumber_ref' => 'MP-1',
        ]);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/sudah terlanjur dipakai/');
        InventoryMovement::batalkanDokumen('JurnalUmum', 'BELI-1', $this->admin);
    }

    public function test_pembatalan_pengeluaran_mengembalikan_lapisan_yang_persis(): void
    {
        $this->barang('BRG0001');
        $this->beli('BRG0001', '100', '1000000', '2026-07-01', 'BELI-1');  // @10.000
        $this->beli('BRG0001', '100', '1500000', '2026-08-01', 'BELI-2');  // @15.000

        InventoryMovement::keluar([
            'kode_persediaan' => 'BRG0001', 'kuantiti' => '150',
            'tanggal' => '2026-09-01', 'sumber_modul' => 'Persediaan', 'sumber_ref' => 'MP-9',
        ]);
        InventoryMovement::batalkanDokumen('Persediaan', 'MP-9', $this->admin);

        $item = Inventory::find('BRG0001');
        $this->assertSame('2500000.00', $item->nilai_persediaan);

        // Kedua lapisan pulih utuh pada harganya masing-masing.
        $lapisan = LapisanPersediaan::where('kode_persediaan', 'BRG0001')->orderBy('id')->get();
        $this->assertSame(['100.0000', '100.0000'], $lapisan->pluck('kuantiti_sisa')->all());
    }

    // ---- A2: pencocokan lewat kode_persediaan, bukan kode_coa ----

    public function test_invoice_menambah_stok_barang_yang_dipilih_bukan_yang_seakun(): void
    {
        // Dua barang BERBAGI satu akun COA — persis keadaan normal di dapur.
        $this->barang('BRG0001', 'Beras');
        $this->barang('BRG0002', 'Minyak');

        (new InvoiceService)->create([
            'nomor_invoice' => 'INV-9', 'tanggal_invoice' => '2026-07-01',
            'tanggal_jatuh_tempo' => '2026-07-31', 'kode_vendor' => 'VZZFF',
            'kode_unit' => self::UNIT, 'kode_coa_hutang' => self::HUTANG,
            'keterangan' => 'Beli minyak',
            'details' => [[
                'kode_coa' => self::PERSEDIAAN, 'kuantiti' => '20', 'harga_satuan' => '25000',
                'kode_persediaan' => 'BRG0002', 'keterangan' => 'Minyak goreng',
            ]],
        ], $this->admin);

        // Minyak yang bertambah — bukan Beras yang kebetulan berakun sama dan
        // lebih dulu dalam urutan.
        $this->assertSame('500000.00', Inventory::find('BRG0002')->nilai_persediaan);
        $this->assertSame('0.00', Inventory::find('BRG0001')->nilai_persediaan);
    }

    public function test_form_invoice_mengalirkan_item_persediaan_sampai_ke_stok(): void
    {
        $this->barang('BRG0001', 'Beras');
        $this->barang('BRG0002', 'Minyak');

        // Lewat HTTP: yang diuji adalah rantai formulir → request → service.
        // Tanpa ini, `kode_persediaan` bisa saja lolos di service tetapi tak
        // pernah sampai ke sana karena tak divalidasi request-nya.
        $this->actingAs(User::find($this->admin))
            ->post(route('invoices.store'), [
                'nomor_invoice' => 'INV-HTTP-1',
                'tanggal_invoice' => '2026-07-01',
                'tanggal_jatuh_tempo' => '2026-07-31',
                'kode_vendor' => 'VZZFF',
                'kode_unit' => self::UNIT,
                'kode_coa_hutang' => self::HUTANG,
                'keterangan' => 'Beli minyak',
                'details' => [[
                    'kode_coa' => self::PERSEDIAAN, 'kuantiti' => '20', 'harga_satuan' => '25000',
                    'kode_persediaan' => 'BRG0002', 'keterangan' => 'Minyak goreng',
                ]],
            ])
            ->assertRedirect();

        $this->assertSame('500000.00', Inventory::find('BRG0002')->nilai_persediaan);
        $this->assertSame('0.00', Inventory::find('BRG0001')->nilai_persediaan);
    }

    // ---- A3: mutasi gudang berjurnal ----

    public function test_pemakaian_berjurnal_debet_beban_kredit_persediaan(): void
    {
        $this->barang('BRG0001');
        $this->beli('BRG0001', '100', '1000000', '2026-07-01');

        $mutasi = (new PersediaanService)->pemakaian([
            'kode_persediaan' => 'BRG0001', 'jumlah' => '30',
            'tanggal' => '2026-07-10', 'kode_bagian' => self::BAGIAN,
        ], $this->admin);

        $this->assertSame('300000.00', $mutasi->nilai);
        $this->assertNotNull($mutasi->journal_entry_id);

        $lines = JournalLine::where('entry_id', $mutasi->journal_entry_id)->get()->keyBy('kode_coa');
        $this->assertSame('300000.00', $lines[self::BEBAN]->debet);
        $this->assertSame('300000.00', $lines[self::PERSEDIAAN]->kredit);
        // Bagian yang dipilih petugas gudang ikut ke baris bebannya.
        $this->assertSame(self::BAGIAN, $lines[self::BEBAN]->kode_bagian);
    }

    public function test_pemakaian_ditolak_bila_akun_bebannya_belum_diisi(): void
    {
        $item = $this->barang('BRG0001');
        $item->update(['kode_coa_beban' => null]);
        $this->beli('BRG0001', '10', '100000', '2026-07-01');

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/Akun Beban Pemakaian/');
        (new PersediaanService)->pemakaian([
            'kode_persediaan' => 'BRG0001', 'jumlah' => '1',
            'tanggal' => '2026-07-10', 'kode_bagian' => self::BAGIAN,
        ], $this->admin);
    }

    // ---- Opname ----

    public function test_opname_menentukan_arah_dan_besarnya_sendiri(): void
    {
        $this->barang('BRG0001');
        $this->beli('BRG0001', '100', '1000000', '2026-07-01');   // @10.000
        $svc = new PersediaanService;

        // Fisik 90 → kurang 10.
        $kurang = $svc->opname([
            'kode_persediaan' => 'BRG0001', 'stok_fisik' => '90',
            'tanggal' => '2026-07-10', 'kode_bagian' => self::BAGIAN,
        ], $this->admin);
        $this->assertSame('keluar', $kurang->arah);
        $this->assertSame('10.0000', $kurang->kuantiti);
        $this->assertSame('100000.00', $kurang->nilai);

        // Fisik 95 → lebih 5, dihargai rata-rata berjalan.
        $lebih = $svc->opname([
            'kode_persediaan' => 'BRG0001', 'stok_fisik' => '95',
            'tanggal' => '2026-07-11', 'kode_bagian' => self::BAGIAN,
        ], $this->admin);
        $this->assertSame('masuk', $lebih->arah);
        $this->assertSame('5.0000', $lebih->kuantiti);

        // Fisik sama dengan catatan → tak ada penyesuaian sama sekali.
        $this->assertNull($svc->opname([
            'kode_persediaan' => 'BRG0001', 'stok_fisik' => '95',
            'tanggal' => '2026-07-12', 'kode_bagian' => self::BAGIAN,
        ], $this->admin));
    }

    public function test_opname_memakai_akun_selisih_bukan_akun_beban(): void
    {
        $this->barang('BRG0001');
        $this->beli('BRG0001', '100', '1000000', '2026-07-01');

        $mutasi = (new PersediaanService)->opname([
            'kode_persediaan' => 'BRG0001', 'stok_fisik' => '95',
            'tanggal' => '2026-07-10', 'kode_bagian' => self::BAGIAN,
        ], $this->admin);

        $kode = JournalLine::where('entry_id', $mutasi->journal_entry_id)->pluck('kode_coa')->all();
        $this->assertContains(self::SELISIH, $kode);
        $this->assertNotContains(self::BEBAN, $kode);
    }

    // ---- Jurnal umum: kredit harus sama dengan harga pokok FIFO ----

    public function test_jurnal_umum_menolak_pengeluaran_stok_yang_nominalnya_tak_sesuai_fifo(): void
    {
        $this->barang('BRG0001');
        $this->beli('BRG0001', '100', '1000000', '2026-07-01');   // @10.000

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/harga pokok FIFO/');
        (new JournalService)->create([
            'tanggal' => '2026-07-10', 'keterangan' => 'Keluarkan 10 kg',
            'id_pengguna' => $this->admin,
            'lines' => [
                ['kode_coa' => self::BEBAN, 'debet' => '999000', 'kredit' => '0', 'kode_bagian' => self::BAGIAN],
                ['kode_coa' => self::PERSEDIAAN, 'debet' => '0', 'kredit' => '999000',
                    'kode_persediaan' => 'BRG0001', 'kuantiti' => '10'],
            ],
        ]);
    }

    public function test_jurnal_umum_menerima_pengeluaran_stok_yang_sesuai_fifo(): void
    {
        $this->barang('BRG0001');
        $this->beli('BRG0001', '100', '1000000', '2026-07-01');

        (new JournalService)->create([
            'tanggal' => '2026-07-10', 'keterangan' => 'Keluarkan 10 kg',
            'id_pengguna' => $this->admin,
            'lines' => [
                ['kode_coa' => self::BEBAN, 'debet' => '100000', 'kredit' => '0', 'kode_bagian' => self::BAGIAN],
                ['kode_coa' => self::PERSEDIAAN, 'debet' => '0', 'kredit' => '100000',
                    'kode_persediaan' => 'BRG0001', 'kuantiti' => '10'],
            ],
        ]);

        $this->assertSame('900000.00', Inventory::find('BRG0001')->nilai_persediaan);
    }

    // ---- Halaman kartu stok ----

    public function test_halaman_kartu_stok_terbuka_dan_menampilkan_lapisannya(): void
    {
        $this->barang('BRG0001');
        $this->beli('BRG0001', '100', '1000000', '2026-07-01', 'BELI-1');

        $this->actingAs(User::find($this->admin))
            ->get(route('inventory.kartu', 'BRG0001'))
            ->assertOk()
            ->assertSee('Kartu Stok')
            ->assertSee('Lapisan FIFO tersisa')
            ->assertSee('BELI-1')
            ->assertDontSee('Belum ada pergerakan untuk barang ini');
    }
}
