<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\BarcodePersediaan;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\ImporBatch;
use App\Models\Inventory;
use App\Models\JournalLine;
use App\Models\KategoriBarang;
use App\Models\Level;
use App\Models\LokasiPersediaan;
use App\Models\MerkBarang;
use App\Models\MutasiPersediaan;
use App\Models\SatuanPersediaan;
use App\Models\User;
use App\Services\Impor\ImporSaldoAwal;
use App\Services\Ledger\InventoryMovement;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * IMPOR DATA AWAL WAFI MART — master barang toko & stok awal per lokasi (19 Okt 2026).
 *
 * Yang dijaga:
 *  • Barcode POS lama ("….00") dirapikan; notasi ilmiah Excel & barcode kembar ditolak.
 *  • Kategori/merk dibuat otomatis; berkas yang sama aman diunggah ulang (dilewati).
 *  • Stok awal berjurnal D Persediaan / K perantara, satu jurnal per unit lokasi.
 *  • Batch bisa dibatalkan utuh selama barangnya belum dipakai.
 */
class ImporBarangTokoTest extends TestCase
{
    use RefreshDatabase;

    private const PERSEDIAAN = '1.ZZIB.PRS';

    private const PERANTARA = '3.ZZIB.SAW';

    private const BEBAN = '5.ZZIB.BBN';

    private const SELISIH = '5.ZZIB.SLS';

    private ImporSaldoAwal $impor;

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        CoaGroup::create(['kode_grup' => 'ZZIB', 'nama_grup' => 'Impor']);
        foreach ([[self::PERSEDIAAN, 'debet'], [self::PERANTARA, 'kredit'], [self::BEBAN, 'debet'], [self::SELISIH, 'debet']] as [$k, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $k, 'kode_grup' => 'ZZIB', 'jenis_saldo' => $s]);
        }
        BusinessUnit::create(['kode_unit' => 'ZZIBU', 'nama_unit' => 'Wafi Mart']);
        LokasiPersediaan::create(['kode' => 'L002', 'nama' => 'Koperasi Banat', 'jenis' => 'toko', 'kode_unit' => 'ZZIBU', 'status' => 'aktif']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        $user = User::create(['username' => 'admib', 'nama' => 'Admin', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => true])->fresh();
        $this->actingAs($user);
        $this->admin = $user->id_pengguna;
        $this->impor = new ImporSaldoAwal;
    }

    private function berkas(array $baris): string
    {
        $path = tempnam(sys_get_temp_dir(), 'impor').'.csv';
        $f = fopen($path, 'w');
        foreach ($baris as $b) {
            fputcsv($f, $b, ',', '"', '\\');
        }
        fclose($f);

        return $path;
    }

    private function paramBarang(): array
    {
        return ['kode_coa' => self::PERSEDIAAN, 'kode_coa_beban' => self::BEBAN, 'kode_coa_selisih' => self::SELISIH];
    }

    private function paramStok(): array
    {
        return ['tanggal_cutoff' => '2026-09-30', 'akun_perantara' => self::PERANTARA];
    }

    private const JUDUL_BARANG = ['kode_barang', 'nama', 'satuan', 'kategori', 'merk', 'barcode', 'harga_beli', 'harga_jual',
        'stok_minimum', 'satuan_besar', 'isi_satuan_besar', 'harga_jual_satuan_besar', 'barcode_satuan_besar'];

    private function berkasBarang(): string
    {
        return $this->berkas([
            self::JUDUL_BARANG,
            ['', 'Teh Kotak', 'pcs', 'Minuman', 'Sosro', '8998009010231.00;0089686010015', '3.000', '4000', '24', 'DUS', '24', '90000', '8998009010248'],
            ['', 'Roti Coklat', 'pcs', 'Makanan', '', '8991111111111', '4000', '5500', '', '', '', '', ''],
            ['', 'Pulpen', 'pcs', 'minuman', '', '8,99123E+12', '1000', '2000', '', '', '', '', ''],          // barcode rusak
            ['', 'Air Mineral', 'botol', 'Minuman', '', '8991111111111', '2000', '3000', '', '', '', '', ''], // barcode kembar baris 2
            ['', 'Sabun', 'pcs', '', '', '', '', '', '', '', '', '', ''],                                     // harga jual kosong
        ]);
    }

    // ---- Barang ----

    public function test_impor_barang_merapikan_barcode_dan_membuat_kategori(): void
    {
        $pratinjau = $this->impor->pratinjau('barang-toko', $this->berkasBarang(), $this->paramBarang());
        $this->assertSame([5, 2, 0, 3], [$pratinjau['jumlah'], $pratinjau['siap'], $pratinjau['lewati'], $pratinjau['masalah']]);
        $alasan = implode(' | ', array_column($pratinjau['baris_masalah'], 'alasan'));
        $this->assertStringContainsString('notasi ilmiah', $alasan);
        $this->assertStringContainsString('Barcode 8991111111111 sudah dipakai baris data ke-2', $alasan);
        $this->assertStringContainsString('Harga jual kosong', $alasan);

        $hasil = $this->impor->jalankan('barang-toko', $this->berkasBarang(), $this->paramBarang(), $this->admin);
        $this->assertSame(['barang' => 2, 'barcode' => 4, 'kategori' => 2, 'merk' => 1], $hasil['tersimpan']);

        $teh = Inventory::where('nama_persediaan', 'Teh Kotak')->sole();
        $this->assertSame(['BRG0001', true, '4000.00', '3000.00', self::PERSEDIAAN], [$teh->kode_persediaan, $teh->dijual_di_toko, $teh->harga_jual, $teh->harga_perolehan, $teh->kode_coa]);
        $this->assertSame('Minuman', $teh->kategori->nama);
        // ".00" buatan POS lama dibuang; nol di depan utuh; barcode dus menunjuk satuan DUS.
        $this->assertEqualsCanonicalizing([['8998009010231', null], ['0089686010015', null], ['8998009010248', 'DUS']],
            $teh->barcode->map(fn ($b) => [$b->barcode, $b->satuan])->all());
        $this->assertSame(['DUS', '24.0000', '90000.00'], [$teh->satuanLain[0]->satuan, $teh->satuanLain[0]->isi, $teh->satuanLain[0]->harga_jual]);
        $this->assertSame($hasil['id_batch'], $teh->id_batch);

        // Diunggah ulang: yang sudah masuk dilewati, tak ada yang siap.
        $ulang = $this->impor->pratinjau('barang-toko', $this->berkasBarang(), $this->paramBarang());
        $this->assertSame([0, 2], [$ulang['siap'], $ulang['lewati']]);
    }

    public function test_kategori_yang_sudah_ada_dipakai_tidak_dibuat_ulang(): void
    {
        KategoriBarang::create(['kode' => 'MNM', 'nama' => 'Minuman']);
        $this->impor->jalankan('barang-toko', $this->berkasBarang(), $this->paramBarang(), $this->admin);

        $this->assertSame('MNM', Inventory::where('nama_persediaan', 'Teh Kotak')->value('kode_kategori'));
        $this->assertSame(['MNM', 'KTG001'], KategoriBarang::orderBy('kode', 'desc')->pluck('kode')->all());
    }

    // ---- Stok awal ----

    private function siapkanBarang(): int
    {
        return $this->impor->jalankan('barang-toko', $this->berkasBarang(), $this->paramBarang(), $this->admin)['id_batch'];
    }

    private function berkasStok(): string
    {
        return $this->berkas([
            ['kode_barang', 'barcode', 'lokasi', 'jumlah', 'harga_pokok'],
            ['BRG0001', '', 'Koperasi Banat', '48', ''],                 // harga dari master @3.000
            ['', '8991111111111.00', 'L001', '10', '3.800'],              // lewat barcode, ke gudang
            ['BRG0001', '', 'koperasi banat', '5', ''],                   // kembar barang|lokasi
            ['BRG9999', '', 'Koperasi Banat', '1', ''],                   // barang tak ada
            ['BRG0002', '', 'Toko Antah', '1', ''],                       // lokasi tak ada
        ]);
    }

    public function test_stok_awal_berjurnal_ke_perantara_per_unit(): void
    {
        $this->siapkanBarang();

        $p = $this->impor->pratinjau('stok-awal-barang', $this->berkasStok(), $this->paramStok());
        $this->assertSame([2, 3], [$p['siap'], $p['masalah']]);

        $hasil = $this->impor->jalankan('stok-awal-barang', $this->berkasStok(), $this->paramStok(), $this->admin);
        $this->assertSame(['baris_stok' => 2, 'jurnal' => 2, 'nilai_rupiah' => 182000], $hasil['tersimpan']);   // 48×3.000 + 10×3.800

        $this->assertSame('48.0000', InventoryMovement::stok('BRG0001', 'L002'));
        $this->assertSame('10.0000', InventoryMovement::stok('BRG0002', 'L001'));
        $m = MutasiPersediaan::where('kode_persediaan', 'BRG0001')->sole();
        $this->assertSame(['saldo_awal', '2026-09-30'], [$m->alasan, $m->tanggal->toDateString()]);
        $this->assertNotNull($m->journal_entry_id);

        // Buku besar = buku pembantu.
        $saldo = fn ($coa) => Money::sub(JournalLine::where('kode_coa', $coa)->sum('debet'), JournalLine::where('kode_coa', $coa)->sum('kredit'));
        $this->assertSame('182000.00', $saldo(self::PERSEDIAAN));
        $this->assertSame('-182000.00', $saldo(self::PERANTARA));
        $this->assertSame('182000.00', Money::of(Inventory::sum('nilai_persediaan')));
        $this->assertSame(['ZZIBU'], JournalLine::where('entry_id', $m->journal_entry_id)->pluck('kode_unit')->unique()->values()->all());

        // Diunggah ulang: yang sudah punya stok awal di lokasi itu dilewati.
        $this->assertSame(0, $this->impor->pratinjau('stok-awal-barang', $this->berkasStok(), $this->paramStok())['siap']);
    }

    public function test_akun_perantara_tidak_boleh_beban_atau_aset(): void
    {
        foreach ([self::BEBAN => 'Beban', self::PERSEDIAAN => 'aset'] as $akun => $pesan) {
            try {
                $this->impor->jalankan('stok-awal-barang', $this->berkasStok(), ['tanggal_cutoff' => '2026-09-30', 'akun_perantara' => $akun], $this->admin);
                $this->fail("Akun {$akun} seharusnya ditolak.");
            } catch (AppException $e) {
                $this->assertStringContainsString($pesan, $e->getMessage());
            }
        }
    }

    // ---- Pembatalan batch ----

    public function test_batal_batch_stok_lalu_barang_dan_halangannya(): void
    {
        $batchBarang = $this->siapkanBarang();
        $batchStok = $this->impor->jalankan('stok-awal-barang', $this->berkasStok(), $this->paramStok(), $this->admin)['id_batch'];

        // Barang yang sudah berstok tak bisa dibatalkan sebelum stoknya.
        $this->assertStringContainsString('pergerakan stok', implode(' ', $this->impor->halanganBatalBatch(ImporBatch::find($batchBarang))));

        // Stok yang sudah terjual menghalangi pembatalan stok.
        $jual = InventoryMovement::keluar(['kode_persediaan' => 'BRG0001', 'kode_lokasi' => 'L002', 'kuantiti' => '1', 'sumber_modul' => 'Penjualan', 'sumber_ref' => 'JL-1']);
        $this->assertStringContainsString('sudah terpakai', implode(' ', $this->impor->halanganBatalBatch(ImporBatch::find($batchStok))));
        InventoryMovement::batalkanDokumen('Penjualan', 'JL-1');

        $this->impor->batalkanBatch($batchStok, 'harga pokok salah', $this->admin);
        $this->assertSame('0.0000', InventoryMovement::stok('BRG0001'));
        $saldo = Money::sub(JournalLine::where('kode_coa', self::PERSEDIAAN)->sum('debet'), JournalLine::where('kode_coa', self::PERSEDIAAN)->sum('kredit'));
        $this->assertSame('0.00', $saldo);

        // Stok awalnya sudah dibatalkan → master barangnya kini boleh dibatalkan juga,
        // KECUALI barang yang punya pergerakan lain (di sini penjualan JL-1 & pembatalannya).
        $this->assertStringContainsString('1 barang sudah punya pergerakan stok', implode(' ', $this->impor->halanganBatalBatch(ImporBatch::find($batchBarang))));
        MutasiPersediaan::where('sumber_ref', 'JL-1')->delete();   // seolah penjualan itu tak pernah ada
        $this->assertSame([], $this->impor->halanganBatalBatch(ImporBatch::find($batchBarang)));
        $this->impor->batalkanBatch($batchBarang, 'berkas keliru', $this->admin);
        $this->assertSame(0, Inventory::count());
    }

    public function test_batal_batch_barang_yang_belum_dipakai(): void
    {
        KategoriBarang::create(['kode' => 'MKN', 'nama' => 'Makanan']);
        $batch = $this->siapkanBarang();
        $this->assertSame([], $this->impor->halanganBatalBatch(ImporBatch::find($batch)));

        $this->impor->batalkanBatch($batch, 'kolom harga tertukar', $this->admin);

        $this->assertSame(0, Inventory::count());
        $this->assertSame(0, BarcodePersediaan::count());
        $this->assertSame(0, SatuanPersediaan::count());
        // Kategori bawaan (bukan dari batch) tetap; yang lahir dari batch ikut hilang.
        $this->assertSame(['MKN'], KategoriBarang::pluck('kode')->all());
        $this->assertSame(0, MerkBarang::count());
        $this->assertNotNull(ImporBatch::find($batch)->dibatalkan_pada);
    }

    public function test_jenis_baru_muncul_di_layar_impor_dan_templatenya(): void
    {
        $this->get(route('impor_data_awal.index', ['jenis' => 'barang-toko']))->assertOk()
            ->assertSee('Barang Toko (Wafi Mart)')->assertSee('Stok Awal Barang per Lokasi');
        $this->get(route('impor_data_awal.template', 'stok-awal-barang'))->assertOk()
            ->assertSee('kode_barang,barcode,lokasi,jumlah,harga_pokok', false);
    }
}
