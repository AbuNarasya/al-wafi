<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\Bagian;
use App\Models\BarcodePersediaan;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\Inventory;
use App\Models\JournalLine;
use App\Models\KategoriBarang;
use App\Models\LapisanPersediaan;
use App\Models\Level;
use App\Models\LokasiPersediaan;
use App\Models\MerkBarang;
use App\Models\MutasiPersediaan;
use App\Models\User;
use App\Services\Ledger\InventoryMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WAFI MART TAHAP 1a — persediaan per LOKASI + data barang toko.
 *
 * Yang dijaga:
 *  • FIFO per lokasi: keluar dari toko hanya menggerus lapisan toko itu, dan
 *    stok total yang cukup tak boleh menutupi stok lokasi yang kurang.
 *  • Pembatalan memulihkan lapisan di lokasi asalnya.
 *  • Pemanggil lama (tanpa lokasi) tetap jatuh ke lokasi bawaan.
 *  • Barcode unik di seluruh barang, disimpan sebagai teks utuh.
 */
class PersediaanLokasiTokoTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZLK';

    private const PERSEDIAAN = '1.ZZLK.PRS';

    private const BEBAN = '5.ZZLK.BBN';

    private const SELISIH = '5.ZZLK.SLS';

    private const UNIT_TOKO = 'ZZLKT';

    private const BAGIAN = 'BZZLK';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Lokasi']);
        foreach ([
            [self::PERSEDIAAN, 'Persediaan Toko', 'debet'],
            [self::BEBAN, 'Beban Pemakaian', 'debet'],
            [self::SELISIH, 'Selisih Persediaan', 'debet'],
        ] as [$k, $n, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => self::GRP, 'jenis_saldo' => $s]);
        }
        BusinessUnit::create(['kode_unit' => self::UNIT_TOKO, 'nama_unit' => 'Wafi Mart']);
        Bagian::create(['kode_bagian' => self::BAGIAN, 'nama_bagian' => 'Koperasi', 'level' => 3]);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);

        LokasiPersediaan::create(['kode' => 'L002', 'nama' => 'Koperasi Banat', 'jenis' => 'toko', 'kode_unit' => self::UNIT_TOKO, 'status' => 'aktif', 'urutan' => 2]);

        $this->admin = User::create([
            'username' => 'admlk', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'tim_keuangan' => true,
        ])->fresh();   // status 'aktif' datang dari nilai bawaan kolom, bukan dari create()
    }

    private function barang(string $kode = 'BRG0001', string $nama = 'Air Mineral'): Inventory
    {
        return Inventory::create([
            'kode_persediaan' => $kode, 'nama_persediaan' => $nama, 'satuan' => 'botol',
            'harga_perolehan' => '0', 'kode_coa' => self::PERSEDIAAN,
            'kode_coa_beban' => self::BEBAN, 'kode_coa_selisih' => self::SELISIH, 'status' => 'aktif',
        ]);
    }

    private function masuk(string $lokasi, string $qty, string $nilai, string $tanggal, string $ref): MutasiPersediaan
    {
        return InventoryMovement::masuk([
            'kode_persediaan' => 'BRG0001', 'kode_lokasi' => $lokasi, 'kuantiti' => $qty, 'nilai' => $nilai,
            'tanggal' => $tanggal, 'sumber_modul' => 'JurnalUmum', 'sumber_ref' => $ref,
        ]);
    }

    private function formBarang(array $tambahan = []): array
    {
        return [
            'nama_persediaan' => 'Teh Kotak', 'satuan' => 'pcs', 'harga_perolehan' => '3000',
            'kode_coa' => self::PERSEDIAAN, 'kode_coa_beban' => self::BEBAN, 'kode_coa_selisih' => self::SELISIH,
            'status' => 'aktif', ...$tambahan,
        ];
    }

    // ---- FIFO per lokasi ----

    public function test_keluar_hanya_menggerus_lapisan_di_lokasinya(): void
    {
        $this->barang();
        // Lapisan TERTUA ada di gudang; toko punya lapisan yang lebih muda dan lebih mahal.
        $this->masuk('L001', '10', '10000', '2026-07-01', 'G-1');   // @1.000
        $this->masuk('L002', '5', '10000', '2026-07-05', 'T-1');    // @2.000

        $keluar = InventoryMovement::keluar([
            'kode_persediaan' => 'BRG0001', 'kode_lokasi' => 'L002', 'kuantiti' => '3',
            'tanggal' => '2026-07-10', 'sumber_modul' => 'Persediaan', 'sumber_ref' => 'MP-1',
        ]);

        // FIFO global akan mengambil @1.000 dari gudang (3.000) — yang benar @2.000 dari toko.
        $this->assertSame('6000.00', $keluar->nilai);
        $this->assertSame('L002', $keluar->kode_lokasi);
        $this->assertSame('2.0000', $keluar->saldo_kuantiti_lokasi);
        $this->assertSame('12.0000', $keluar->saldo_kuantiti);

        $this->assertSame('10.0000', InventoryMovement::stok('BRG0001', 'L001'));
        $this->assertSame('2.0000', InventoryMovement::stok('BRG0001', 'L002'));
        $this->assertSame(['BRG0001' => ['L001' => '10.0000', 'L002' => '2.0000']], InventoryMovement::stokPerLokasi());
    }

    public function test_stok_total_cukup_tidak_menutupi_stok_lokasi_yang_kurang(): void
    {
        $this->barang();
        $this->masuk('L001', '100', '100000', '2026-07-01', 'G-1');
        $this->masuk('L002', '2', '2000', '2026-07-01', 'T-1');

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/Air Mineral di Koperasi Banat tidak cukup: diminta 5\.0000, tersedia 2\.0000/');
        InventoryMovement::keluar([
            'kode_persediaan' => 'BRG0001', 'kode_lokasi' => 'L002', 'kuantiti' => '5',
            'sumber_modul' => 'Persediaan',
        ]);
    }

    public function test_tanpa_lokasi_jatuh_ke_lokasi_bawaan(): void
    {
        $this->barang();
        $m = InventoryMovement::masuk([
            'kode_persediaan' => 'BRG0001', 'kuantiti' => '4', 'nilai' => '4000', 'sumber_modul' => 'Invoice', 'sumber_ref' => 'INV-1',
        ]);

        $this->assertSame(LokasiPersediaan::BAWAAN, $m->kode_lokasi);
        $this->assertSame(LokasiPersediaan::BAWAAN, LapisanPersediaan::first()->kode_lokasi);
    }

    public function test_pembatalan_pengeluaran_memulihkan_lapisan_di_lokasi_asal(): void
    {
        $this->barang();
        $this->masuk('L001', '10', '10000', '2026-07-01', 'G-1');
        $this->masuk('L002', '5', '10000', '2026-07-05', 'T-1');
        InventoryMovement::keluar([
            'kode_persediaan' => 'BRG0001', 'kode_lokasi' => 'L002', 'kuantiti' => '5',
            'sumber_modul' => 'Persediaan', 'sumber_ref' => 'MP-9',
        ]);

        InventoryMovement::batalkanDokumen('Persediaan', 'MP-9');

        $this->assertSame('5.0000', InventoryMovement::stok('BRG0001', 'L002'));
        $this->assertSame('10.0000', InventoryMovement::stok('BRG0001', 'L001'));
        $pembalik = MutasiPersediaan::where('alasan', 'pembatalan')->sole();
        $this->assertSame('L002', $pembalik->kode_lokasi);
        $this->assertSame('5.0000', $pembalik->saldo_kuantiti_lokasi);
    }

    public function test_lokasi_nonaktif_menolak_pergerakan_baru(): void
    {
        $this->barang();
        LokasiPersediaan::whereKey('L002')->update(['status' => 'nonaktif']);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/Koperasi Banat sudah nonaktif/');
        $this->masuk('L002', '1', '1000', '2026-07-01', 'T-1');
    }

    // ---- Pemakaian & opname per lokasi ----

    public function test_opname_membandingkan_stok_lokasinya_dan_jurnal_ke_unit_lokasi(): void
    {
        $this->barang();
        $this->masuk('L001', '50', '50000', '2026-07-01', 'G-1');
        $this->masuk('L002', '10', '10000', '2026-07-01', 'T-1');

        // Hitungan rak toko = 8. Selisih -2 terhadap stok TOKO (10), bukan total (60).
        $this->actingAs($this->admin)->post(route('inventory.mutasi', 'BRG0001'), [
            'jenis' => 'opname', 'tanggal' => '2026-07-15', 'stok_fisik' => '8',
            'kode_bagian' => self::BAGIAN, 'kode_lokasi' => 'L002',
        ])->assertRedirect(route('inventory.index'))->assertSessionHas('status');

        $m = MutasiPersediaan::where('alasan', 'opname')->sole();
        $this->assertSame(['keluar', '2.0000', 'L002'], [$m->arah, $m->kuantiti, $m->kode_lokasi]);
        $this->assertSame('50.0000', InventoryMovement::stok('BRG0001', 'L001'));

        // Unit jurnalnya = unit pemilik lokasi (dulu layar ini tak menyebut unit sama sekali).
        $this->assertSame([self::UNIT_TOKO], JournalLine::where('entry_id', $m->journal_entry_id)->pluck('kode_unit')->unique()->values()->all());
    }

    public function test_pemakaian_dari_lokasi_nonaktif_ditolak_validasi(): void
    {
        $this->barang();
        $this->masuk('L002', '10', '10000', '2026-07-01', 'T-1');
        LokasiPersediaan::whereKey('L002')->update(['status' => 'nonaktif']);

        $this->actingAs($this->admin)->post(route('inventory.mutasi', 'BRG0001'), [
            'jenis' => 'pemakaian', 'tanggal' => '2026-07-15', 'jumlah' => '1',
            'kode_bagian' => self::BAGIAN, 'kode_lokasi' => 'L002',
        ])->assertSessionHasErrors('kode_lokasi');
    }

    // ---- Master lokasi ----

    public function test_lokasi_berstok_dan_lokasi_bawaan_tak_bisa_dinonaktifkan(): void
    {
        $this->barang();
        $this->masuk('L002', '1', '1000', '2026-07-01', 'T-1');
        $form = ['nama' => 'Koperasi Banat', 'jenis' => 'toko', 'status' => 'nonaktif'];

        $this->actingAs($this->admin)->put(route('lokasi_persediaan.update', 'L002'), $form)
            ->assertSessionHas('error', fn ($p) => str_contains($p, 'masih menyimpan stok'));
        $this->assertSame('aktif', LokasiPersediaan::find('L002')->status);

        $this->actingAs($this->admin)->put(route('lokasi_persediaan.update', 'L001'), [...$form, 'nama' => 'Gudang Utama', 'jenis' => 'gudang'])
            ->assertSessionHas('error', fn ($p) => str_contains($p, 'lokasi bawaan'));
    }

    public function test_tambah_lokasi_memberi_kode_berurutan(): void
    {
        $this->actingAs($this->admin)->post(route('lokasi_persediaan.store'), [
            'nama' => 'Koperasi Banin', 'jenis' => 'toko', 'kode_unit' => self::UNIT_TOKO, 'status' => 'aktif',
        ])->assertRedirect(route('lokasi_persediaan.index'));

        $this->assertSame('Koperasi Banin', LokasiPersediaan::find('L003')?->nama);
        $this->actingAs($this->admin)->get(route('lokasi_persediaan.index'))->assertOk()
            ->assertSee('Koperasi Banin')->assertSee('Wafi Mart');
    }

    // ---- Data toko: satuan konversi, barcode, harga jual ----

    public function test_barang_toko_tersimpan_beserta_satuan_dan_barcode(): void
    {
        KategoriBarang::create(['kode' => 'MNM', 'nama' => 'Minuman']);
        MerkBarang::create(['kode' => 'SSR', 'nama' => 'Sosro']);

        $this->actingAs($this->admin)->post(route('inventory.store'), $this->formBarang([
            'kode_kategori' => 'MNM', 'kode_merk' => 'SSR',
            'dijual_di_toko' => '1', 'harga_jual' => '4000', 'stok_minimum' => '24',
            'satuan_lain' => [
                ['satuan' => 'DUS', 'isi' => '24', 'harga_jual' => '90000'],
                ['satuan' => '', 'isi' => '', 'harga_jual' => ''],   // baris kosong dibuang
            ],
            'barcode' => [
                ['barcode' => '0089686010015', 'satuan' => ''],
                ['barcode' => '8998009010231', 'satuan' => 'DUS'],
                ['barcode' => '', 'satuan' => ''],
            ],
        ]))->assertRedirect(route('inventory.index'))->assertSessionHasNoErrors();

        $item = Inventory::with('satuanLain', 'barcode')->sole();
        $this->assertSame(['MNM', 'SSR', true, '4000.00', '24.0000'],
            [$item->kode_kategori, $item->kode_merk, $item->dijual_di_toko, $item->harga_jual, $item->stok_minimum]);
        $this->assertSame([['DUS', '24.0000', '90000.00']],
            $item->satuanLain->map(fn ($s) => [$s->satuan, $s->isi, $s->harga_jual])->all());
        // Nol di depan utuh — barcode adalah teks, bukan angka.
        $this->assertSame([['0089686010015', null], ['8998009010231', 'DUS']],
            $item->barcode->map(fn ($b) => [$b->barcode, $b->satuan])->all());

        // Cari dengan memindai barcode di daftar.
        $this->actingAs($this->admin)->get(route('inventory.index', ['q' => '8998009010231']))
            ->assertOk()->assertSee('Teh Kotak')->assertSee('Minuman');
    }

    public function test_barcode_tak_boleh_dipakai_dua_barang(): void
    {
        $lain = $this->barang();
        BarcodePersediaan::create(['barcode' => '8991111111111', 'kode_persediaan' => $lain->kode_persediaan]);

        $this->actingAs($this->admin)->post(route('inventory.store'), $this->formBarang([
            'barcode' => [['barcode' => '8991111111111', 'satuan' => '']],
        ]))->assertSessionHasErrors(['barcode' => 'Barcode 8991111111111 sudah dipakai Air Mineral.']);

        $this->assertSame(1, Inventory::count());
    }

    public function test_menyimpan_ulang_barang_sendiri_tak_bentrok_dengan_barcodenya(): void
    {
        $item = $this->barang();
        BarcodePersediaan::create(['barcode' => '8992222222222', 'kode_persediaan' => $item->kode_persediaan]);

        $this->actingAs($this->admin)->put(route('inventory.update', 'BRG0001'), $this->formBarang([
            'nama_persediaan' => 'Air Mineral 600ml', 'satuan' => 'botol',
            'barcode' => [['barcode' => '8992222222222', 'satuan' => ''], ['barcode' => '8993333333333', 'satuan' => '']],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(['8992222222222', '8993333333333'], $item->barcode()->pluck('barcode')->all());
    }

    public function test_aturan_isian_toko(): void
    {
        $kirim = fn (array $t) => $this->actingAs($this->admin)->post(route('inventory.store'), $this->formBarang($t));

        // Dijual di toko tanpa harga jual.
        $kirim(['dijual_di_toko' => '1'])->assertSessionHasErrors(['harga_jual' => 'Barang yang dijual di toko wajib punya harga jual.']);
        // Barcode menunjuk satuan yang tak terdaftar.
        $kirim(['barcode' => [['barcode' => '123', 'satuan' => 'PAK']]])->assertSessionHasErrors('barcode.0.satuan');
        // Satuan konversi sama dengan satuan dasar.
        $kirim(['satuan_lain' => [['satuan' => 'PCS', 'isi' => '12']]])->assertSessionHasErrors('satuan_lain');
        // Isi konversi harus > 1.
        $kirim(['satuan_lain' => [['satuan' => 'DUS', 'isi' => '1']]])->assertSessionHasErrors('satuan_lain.0.isi');

        $this->assertSame(0, Inventory::count());
    }

    public function test_kartu_stok_bisa_disaring_per_lokasi(): void
    {
        $this->barang();
        $this->masuk('L001', '10', '10000', '2026-07-01', 'G-1');
        $this->masuk('L002', '3', '6000', '2026-07-02', 'T-1');

        $this->actingAs($this->admin)->get(route('inventory.kartu', ['BRG0001', 'lokasi' => 'L002']))
            ->assertOk()->assertSee('Saldo Lokasi')->assertSee('T-1')->assertDontSee('G-1');

        $this->actingAs($this->admin)->get(route('inventory.index'))
            ->assertOk()->assertSee('Koperasi Banat: 3', false)->assertSee('Gudang Utama: 10', false);
    }

    public function test_kategori_dan_merk_lewat_master_sederhana(): void
    {
        $this->actingAs($this->admin)->post(route('kategori_barang.store'), ['kode' => 'ATK', 'nama' => 'Alat Tulis', 'status' => 'aktif'])
            ->assertRedirect(route('kategori_barang.index'));
        $this->actingAs($this->admin)->post(route('merk_barang.store'), ['kode' => 'JOY', 'nama' => 'Joyko', 'status' => 'aktif'])
            ->assertRedirect(route('merk_barang.index'));

        // <x-field> berpilihan dirender sebagai pemilih ber-cari (data JSON Alpine), bukan <option>.
        // Kutipnya ter-escape " oleh @js, jadi dicocokkan lewat str_replace.
        $html = str_replace('\\u0022', '"', $this->actingAs($this->admin)->get(route('inventory.create'))->assertOk()->getContent());
        $this->assertStringContainsString('{"v":"ATK","l":"Alat Tulis"}', $html);
        $this->assertStringContainsString('{"v":"JOY","l":"Joyko"}', $html);
    }

    // ---- Audit 21 Okt 2026 (Paket 5) ----

    public function test_satuan_barcode_disimpan_dengan_ejaan_resminya(): void
    {
        $this->actingAs($this->admin)->post(route('inventory.store'), $this->formBarang([
            'dijual_di_toko' => '1', 'harga_jual' => '4000',
            'satuan_lain' => [['satuan' => 'DUS', 'isi' => '24', 'harga_jual' => '90000']],
            'barcode' => [['barcode' => '111222333', 'satuan' => 'dus']],
        ]))->assertSessionHasNoErrors();
        $this->assertSame('DUS', \App\Models\BarcodePersediaan::find('111222333')->satuan);
    }

    public function test_barang_berriwayat_stok_terkunci_akunnya_dan_harga_perolehan_turunan(): void
    {
        $this->barang();
        $this->masuk('L001', '10', '30000', '2026-07-01', 'M-1');
        CoaDetail::create(['kode_coa' => '1.ZZLK.LAIN', 'nama_coa' => 'Persediaan Lain', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);

        $this->actingAs($this->admin)->put(route('inventory.update', 'BRG0001'), $this->formBarang(['nama_persediaan' => 'Air Mineral', 'kode_coa' => '1.ZZLK.LAIN']))
            ->assertSessionHasErrors('kode_coa');
        $this->actingAs($this->admin)->put(route('inventory.update', 'BRG0001'), $this->formBarang(['nama_persediaan' => 'Air Mineral', 'harga_perolehan' => '99999']))
            ->assertSessionHasNoErrors();
        $this->assertSame(['1.ZZLK.PRS', '3000.00'], [Inventory::find('BRG0001')->kode_coa, Inventory::find('BRG0001')->harga_perolehan]);
    }

    public function test_transit_dan_opname_langsung_tanpa_hak_persetujuan_ditolak(): void
    {
        $this->barang();
        $this->masuk('L001', '10', '30000', '2026-07-01', 'M-1');
        $this->actingAs($this->admin)->post(route('inventory.mutasi', 'BRG0001'), [
            'jenis' => 'pemakaian', 'tanggal' => '2026-07-02', 'jumlah' => '1', 'kode_bagian' => self::BAGIAN, 'kode_lokasi' => LokasiPersediaan::TRANSIT,
        ])->assertSessionHasErrors('kode_lokasi');

        $gudang = User::create(['username' => 'gdg', 'nama' => 'Gudang', 'password_hash' => 'x', 'kode_level' => $this->admin->kode_level, 'is_admin' => false, 'status' => 'aktif'])->fresh();
        \App\Models\HakAksesModul::create(['id_pengguna' => $gudang->id_pengguna, 'kode_modul' => 'inventory', 'lihat' => true, 'buat' => false, 'ubah' => true, 'hapus' => false, 'menu' => true]);
        \App\Support\Akses::lupakan();
        $this->actingAs($gudang)->post(route('inventory.mutasi', 'BRG0001'), [
            'jenis' => 'opname', 'tanggal' => '2026-07-02', 'stok_fisik' => '0', 'kode_bagian' => self::BAGIAN, 'kode_lokasi' => 'L001',
        ])->assertSessionHas('error');
        $this->assertSame('10.0000', InventoryMovement::stok('BRG0001', 'L001'));
    }

    public function test_kode_barang_berikutnya_memakai_urutan_angka(): void
    {
        $this->barang('BRG9999');
        $this->barang('BRG10000');
        $this->actingAs($this->admin)->post(route('inventory.store'), $this->formBarang())->assertSessionHasNoErrors();
        $this->assertNotNull(Inventory::find('BRG10001'));
    }
}
