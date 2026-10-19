<?php

namespace Tests\Feature;

use App\Http\Controllers\LaporanTokoController;
use App\Models\Bagian;
use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\HakAksesModul;
use App\Models\Inventory;
use App\Models\JournalLine;
use App\Models\KategoriBarang;
use App\Models\LapisanPersediaan;
use App\Models\Level;
use App\Models\LokasiPersediaan;
use App\Models\PinPengawas;
use App\Models\User;
use App\Services\Ledger\InventoryMovement;
use App\Services\Modules\KasirService;
use App\Support\Akses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * LAPORAN TOKO WAFI MART — terlaris & margin, per kategori, perlu dibeli, tidak laku.
 * Angka omzet/HPP harus cocok dengan jurnal ringkas shift untuk periode yang sama.
 */
class LaporanTokoTest extends TestCase
{
    use RefreshDatabase;

    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();
        CoaGroup::create(['kode_grup' => 'ZZLT', 'nama_grup' => 'Laporan']);
        foreach ([['1.ZZLT.PRS', 'debet'], ['1.ZZLT.LCI', 'debet'], ['4.ZZLT.JUAL', 'kredit'], ['5.ZZLT.HPP', 'debet'], ['5.ZZLT.SKS', 'debet']] as [$k, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $k, 'kode_grup' => 'ZZLT', 'jenis_saldo' => $s]);
        }
        BankAccount::create(['kode_coa' => '1.ZZLT.LCI', 'nama_rekening' => 'Laci', 'jenis_rekening' => 'tunai', 'status' => 'aktif']);
        BusinessUnit::create(['kode_unit' => 'ZZLTU', 'nama_unit' => 'Wafi Mart']);
        Bagian::create(['kode_bagian' => 'BZZLT', 'nama_bagian' => 'Koperasi', 'level' => 3]);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Staf', 'max_transaksi' => null]);
        LokasiPersediaan::create([
            'kode' => 'L002', 'nama' => 'Koperasi Banat', 'jenis' => 'toko', 'kode_unit' => 'ZZLTU', 'status' => 'aktif',
            'kode_coa_kas' => '1.ZZLT.LCI', 'kode_coa_pendapatan' => '4.ZZLT.JUAL', 'kode_coa_hpp' => '5.ZZLT.HPP',
            'kode_coa_selisih_kas' => '5.ZZLT.SKS', 'kode_bagian' => 'BZZLT',
        ]);
        KategoriBarang::create(['kode' => 'MNM', 'nama' => 'Minuman']);
        KategoriBarang::create(['kode' => 'ATK', 'nama' => 'Alat Tulis']);
        foreach ([
            ['BRG0001', 'Teh Kotak', 'MNM', '4000', '30', 100, 3000],
            ['BRG0002', 'Air Mineral', 'MNM', '3000', '20', 25, 2000],
            ['BRG0003', 'Pulpen', 'ATK', '2500', null, 40, 1500],
        ] as [$kode, $nama, $kat, $jual, $min, $stok, $harga]) {
            Inventory::create(['kode_persediaan' => $kode, 'nama_persediaan' => $nama, 'satuan' => 'pcs', 'kode_kategori' => $kat,
                'harga_perolehan' => '0', 'harga_jual' => $jual, 'stok_minimum' => $min, 'dijual_di_toko' => true, 'kode_coa' => '1.ZZLT.PRS', 'status' => 'aktif']);
            InventoryMovement::masuk(['kode_persediaan' => $kode, 'kode_lokasi' => 'L002', 'kuantiti' => $stok, 'nilai' => $stok * $harga,
                'tanggal' => now()->subDays(45)->toDateString(), 'sumber_modul' => 'JurnalUmum', 'sumber_ref' => 'AWAL-'.$kode]);
        }

        $this->kasir = User::create(['username' => 'kasir', 'nama' => 'Kasir', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif'])->fresh();
        $spv = User::create(['username' => 'spv', 'nama' => 'Spv', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif'])->fresh();
        HakAksesModul::create(['id_pengguna' => $spv->id_pengguna, 'kode_modul' => 'shift-kasir', 'lihat' => true, 'buat' => false, 'ubah' => true, 'hapus' => false, 'menu' => true]);
        PinPengawas::create(['id_pengguna' => $spv->id_pengguna, 'pin_hash' => Hash::make('482915')]);
        Akses::lupakan();

        // Satu shift: Teh 80 pcs (diskon struk 2.000 di struk kedua), Air 10 pcs; retur 1 Teh layak.
        $k = new KasirService;
        $shift = $k->bukaShift('L002', 0, $this->kasir->id_pengguna);
        $j1 = $k->jual($shift->id_shift, [['kode_persediaan' => 'BRG0001', 'kuantiti' => 60], ['kode_persediaan' => 'BRG0002', 'kuantiti' => 10]], 270000, $this->kasir->id_pengguna);
        $k->jual($shift->id_shift, [['kode_persediaan' => 'BRG0001', 'kuantiti' => 20]], 78000, $this->kasir->id_pengguna,
            ['struk' => 2000, 'alasan' => 'pelanggan', 'id_pengawas' => $spv->id_pengguna, 'pin' => '482915']);
        $k->retur($shift->id_shift, $j1->nomor, [['id_penjualan_detail' => $j1->details[0]->id, 'kuantiti' => 1, 'kondisi' => 'layak']],
            'salah ambil', $spv->id_pengguna, '482915', $this->kasir->id_pengguna);
        $k->tutupShift($shift->id_shift, null, 344000, null, $this->kasir->id_pengguna);   // 270.000 + 78.000 − 4.000 retur
    }

    private function laporan(): LaporanTokoController
    {
        return new LaporanTokoController;
    }

    public function test_terlaris_dan_margin_bersih_retur_cocok_dengan_jurnal(): void
    {
        $rows = $this->laporan()->perBarang(now()->toDateString(), now()->toDateString(), '', '');

        $this->assertSame(['BRG0001', 'BRG0002'], $rows->pluck('kode')->all());   // terlaris di atas
        $teh = $rows[0];
        // 80 terjual − 1 retur; omzet 240.000 + 78.000 − 4.000; HPP 79 × 3.000.
        $this->assertSame([79.0, 314000.0, 237000.0, 77000.0, 24.5], [$teh->qty, $teh->omzet, $teh->hpp, $teh->laba, $teh->margin]);
        $this->assertSame([10.0, 30000.0, 20000.0], [$rows[1]->qty, $rows[1]->omzet, $rows[1]->hpp]);

        // Cocok dengan akun toko pada periode yang sama.
        $pendapatan = (float) JournalLine::where('kode_coa', '4.ZZLT.JUAL')->sum('kredit') - (float) JournalLine::where('kode_coa', '4.ZZLT.JUAL')->sum('debet');
        $hpp = (float) JournalLine::where('kode_coa', '5.ZZLT.HPP')->sum('debet') - (float) JournalLine::where('kode_coa', '5.ZZLT.HPP')->sum('kredit');
        $this->assertSame([$pendapatan, $hpp], [$rows->sum('omzet'), $rows->sum('hpp')]);

        // Saring kategori & periode lain.
        $this->assertSame([], $this->laporan()->perBarang(now()->toDateString(), now()->toDateString(), '', 'ATK')->all());
        $this->assertSame([], $this->laporan()->perBarang(now()->subDays(9)->toDateString(), now()->subDays(8)->toDateString(), '', '')->all());
    }

    public function test_per_kategori(): void
    {
        $rows = $this->laporan()->perKategori(now()->toDateString(), now()->toDateString(), '', '');
        $this->assertCount(1, $rows);
        $this->assertSame(['Minuman', 2, 344000.0, 257000.0], [$rows[0]->nama, $rows[0]->barang, $rows[0]->omzet, $rows[0]->hpp]);
    }

    public function test_perlu_dibeli_dan_saran_jumlah(): void
    {
        // Teh: stok 21 ≤ minimum 30; laku 79 / 30 hari = 2,63/hari → saran ⌈79⌉ − 21 = 58. Air: 15 ≤ 20.
        $rows = $this->laporan()->perluDibeli('', '', 30)->keyBy('kode');
        $this->assertSame(['BRG0001', 'BRG0002'], $rows->keys()->sort()->values()->all());
        $this->assertSame([21.0, 2.63, 7, 58], [$rows['BRG0001']->stok, $rows['BRG0001']->per_hari, $rows['BRG0001']->hari_habis, $rows['BRG0001']->saran]);
        $this->assertSame(5, $rows['BRG0002']->saran);   // laku 10 < minimum 20 → kembali ke minimum: 20 − 15
        // Pulpen tak punya stok minimum → tak pernah muncul.
        $this->assertArrayNotHasKey('BRG0003', $rows->all());
    }

    public function test_tidak_laku_dengan_nilai_dan_umur(): void
    {
        $rows = $this->laporan()->tidakLaku('', '', 30);
        $this->assertSame(['BRG0003'], $rows->pluck('kode')->all());   // Teh & Air laku hari ini
        $this->assertSame([40.0, 60000.0, null, 45], [$rows[0]->stok, $rows[0]->nilai, $rows[0]->terakhir, $rows[0]->umur]);
        $this->assertSame(1, LapisanPersediaan::where('kode_persediaan', 'BRG0003')->count());
    }

    public function test_layar_dan_hak_akses(): void
    {
        $this->actingAs($this->kasir)->get(route('laporan_toko.index'))->assertForbidden();

        $pengelola = User::create(['username' => 'toko', 'nama' => 'Toko', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif'])->fresh();
        HakAksesModul::create(['id_pengguna' => $pengelola->id_pengguna, 'kode_modul' => 'laporan-toko', 'lihat' => true, 'buat' => false, 'ubah' => false, 'hapus' => false, 'menu' => true]);
        Akses::lupakan();

        foreach (['barang' => 'Teh Kotak', 'kategori' => 'Minuman', 'beli' => 'Saran beli', 'tidak-laku' => 'Pulpen'] as $tab => $lihat) {
            $this->actingAs($pengelola)->get(route('laporan_toko.index', ['tab' => $tab]))->assertOk()->assertSee($lihat);
        }
        // Pengelola toko tak otomatis boleh membuka laporan keuangan yayasan.
        $this->actingAs($pengelola)->get('/reports/neraca')->assertForbidden();
    }
}
