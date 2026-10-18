<?php

namespace Tests\Feature;

use App\Models\Bagian;
use App\Models\BankAccount;
use App\Models\BarcodePersediaan;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\HakAksesModul;
use App\Models\Inventory;
use App\Models\Level;
use App\Models\LokasiPersediaan;
use App\Models\Penjualan;
use App\Models\SatuanPersediaan;
use App\Models\ShiftKasir;
use App\Models\User;
use App\Services\Ledger\InventoryMovement;
use App\Support\Akses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WAFI MART TAHAP 2b — LAYAR KASIR /pos (buka shift, cari/pindai, simpan struk, cetak).
 */
class PosTest extends TestCase
{
    use RefreshDatabase;

    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();

        CoaGroup::create(['kode_grup' => 'ZZPS', 'nama_grup' => 'POS']);
        foreach ([['1.ZZPS.PRS', 'debet'], ['1.ZZPS.LCI', 'debet'], ['4.ZZPS.JUAL', 'kredit'], ['5.ZZPS.HPP', 'debet'], ['5.ZZPS.SKS', 'debet']] as [$k, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $k, 'kode_grup' => 'ZZPS', 'jenis_saldo' => $s]);
        }
        BankAccount::create(['kode_coa' => '1.ZZPS.LCI', 'nama_rekening' => 'Laci', 'jenis_rekening' => 'tunai', 'status' => 'aktif']);
        BusinessUnit::create(['kode_unit' => 'ZZPSU', 'nama_unit' => 'Wafi Mart']);
        Bagian::create(['kode_bagian' => 'BZZPS', 'nama_bagian' => 'Koperasi', 'level' => 3]);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Staf', 'max_transaksi' => null]);
        LokasiPersediaan::create([
            'kode' => 'L002', 'nama' => 'Koperasi Banat', 'jenis' => 'toko', 'kode_unit' => 'ZZPSU', 'status' => 'aktif',
            'kode_coa_kas' => '1.ZZPS.LCI', 'kode_coa_pendapatan' => '4.ZZPS.JUAL', 'kode_coa_hpp' => '5.ZZPS.HPP',
            'kode_coa_selisih_kas' => '5.ZZPS.SKS', 'kode_bagian' => 'BZZPS',
        ]);
        foreach ([['BRG0001', 'Teh Kotak', true], ['BRG0002', 'Sabun Gudang', false]] as [$kode, $nama, $toko]) {
            Inventory::create(['kode_persediaan' => $kode, 'nama_persediaan' => $nama, 'satuan' => 'pcs', 'harga_perolehan' => '0',
                'harga_jual' => '4000', 'dijual_di_toko' => $toko, 'kode_coa' => '1.ZZPS.PRS', 'status' => 'aktif']);
        }
        SatuanPersediaan::create(['kode_persediaan' => 'BRG0001', 'satuan' => 'DUS', 'isi' => '24', 'harga_jual' => '90000']);
        BarcodePersediaan::create(['barcode' => '8998009010231', 'kode_persediaan' => 'BRG0001', 'satuan' => 'DUS']);
        InventoryMovement::masuk(['kode_persediaan' => 'BRG0001', 'kode_lokasi' => 'L002', 'kuantiti' => '30', 'nilai' => '90000',
            'sumber_modul' => 'JurnalUmum', 'sumber_ref' => 'AWAL']);

        $this->kasir = $this->pengguna('kasir', ['pos' => ['buat']]);
    }

    private function pengguna(string $u, array $hak = []): User
    {
        $user = User::create(['username' => $u, 'nama' => ucfirst($u), 'password_hash' => 'x', 'kode_level' => 'L1',
            'is_admin' => false, 'status' => 'aktif'])->fresh();
        foreach ($hak as $modul => $aksi) {
            HakAksesModul::create(['id_pengguna' => $user->id_pengguna, 'kode_modul' => $modul, 'lihat' => true,
                'buat' => in_array('buat', $aksi, true), 'ubah' => false, 'hapus' => false, 'menu' => true]);
        }
        Akses::lupakan();

        return $user;
    }

    private function bukaShift(): ShiftKasir
    {
        $this->actingAs($this->kasir)->post(route('pos.buka_shift'), ['kode_lokasi' => 'L002', 'modal_awal' => '150000'])
            ->assertRedirect(route('pos.index'));

        return ShiftKasir::sole();
    }

    public function test_tanpa_shift_tampil_layar_buka_lalu_layar_kasir(): void
    {
        $this->actingAs($this->kasir)->get(route('pos.index'))->assertOk()
            ->assertSee('Buka Shift')->assertSee('Koperasi Banat');

        $shift = $this->bukaShift();
        $this->assertSame(['L002', '150000.00'], [$shift->kode_lokasi, $shift->modal_awal]);

        $this->actingAs($this->kasir)->get(route('pos.index'))->assertOk()
            ->assertSee('Kasir — Koperasi Banat')->assertSee($shift->nomor)->assertSee('BAYAR');
    }

    public function test_cari_lewat_barcode_dan_nama_hanya_barang_toko(): void
    {
        $this->bukaShift();

        $pindai = $this->actingAs($this->kasir)->getJson(route('pos.cari', ['barcode' => '8998009010231']))->assertOk()->json('items');
        $this->assertCount(1, $pindai);
        $this->assertSame(['BRG0001', 'DUS', 30], [$pindai[0]['kode'], $pindai[0]['satuan_dipilih'], (int) $pindai[0]['stok']]);
        // `normal` = harga tanpa promo (pembanding di layar & struk).
        $this->assertEquals([['satuan' => 'pcs', 'isi' => 1, 'harga' => 4000, 'normal' => 4000], ['satuan' => 'DUS', 'isi' => 24, 'harga' => 90000, 'normal' => 90000]], $pindai[0]['opsi']);

        // "Sabun Gudang" tak ditandai dijual di toko → tak muncul.
        $this->assertSame(['BRG0001'], array_column($this->actingAs($this->kasir)->getJson(route('pos.cari', ['q' => 'teh']))->json('items'), 'kode'));
        $this->assertSame([], $this->actingAs($this->kasir)->getJson(route('pos.cari', ['q' => 't']))->json('items'));   // minimal 2 huruf
        $this->assertSame([], $this->actingAs($this->kasir)->getJson(route('pos.cari', ['q' => 'Sabun']))->json('items'));
        $this->assertSame([], $this->actingAs($this->kasir)->getJson(route('pos.cari', ['barcode' => '000']))->json('items'));
    }

    public function test_simpan_struk_json_dan_cetak(): void
    {
        $shift = $this->bukaShift();

        $hasil = $this->actingAs($this->kasir)->postJson(route('pos.jual'), [
            'bayar' => 20000,
            'baris' => [['kode_persediaan' => 'BRG0001', 'satuan' => 'pcs', 'kuantiti' => 4, 'barcode' => null]],
        ])->assertOk()->json();

        $this->assertSame([16000, 4000], [(int) $hasil['total'], (int) $hasil['kembalian']]);
        $this->assertSame(['struk' => 1, 'omzet' => 16000], ['struk' => $hasil['ringkas']['struk'], 'omzet' => (int) $hasil['ringkas']['omzet']]);
        $this->assertSame('26.0000', InventoryMovement::stok('BRG0001', 'L002'));

        $jual = Penjualan::sole();
        $this->actingAs($this->kasir)->get($hasil['struk'])->assertOk()
            ->assertSee($jual->nomor)->assertSee('Teh Kotak')->assertSee('16.000')->assertSee('window.print()', false);
        $this->actingAs($this->kasir)->get(route('pos.riwayat'))->assertOk()->assertSee($jual->nomor);

        // Struk kasir lain tak bisa dibuka.
        $lain = $this->pengguna('kasir2', ['pos' => ['buat']]);
        $this->actingAs($lain)->get(route('pos.struk', $jual->id_penjualan))->assertForbidden();
        $this->assertSame($shift->id_shift, $jual->id_shift);
    }

    public function test_galat_penjualan_dikembalikan_sebagai_pesan_json(): void
    {
        $this->bukaShift();

        $this->actingAs($this->kasir)->postJson(route('pos.jual'), [
            'bayar' => 500000, 'baris' => [['kode_persediaan' => 'BRG0001', 'satuan' => 'DUS', 'kuantiti' => 2]],
        ])->assertStatus(422)->assertJsonFragment(['message' => 'Stok Teh Kotak di Koperasi Banat tidak cukup: diminta 48.0000, tersedia 30.0000.']);

        $this->assertSame(0, Penjualan::count());
    }

    public function test_hak_akses_layar_kasir(): void
    {
        $tanpaHak = $this->pengguna('tamu');
        $this->actingAs($tanpaHak)->get(route('pos.index'))->assertForbidden();

        // Hak lihat saja: boleh membuka layar, tak boleh buka shift / berjualan.
        $pengamat = $this->pengguna('pengamat', ['pos' => []]);
        $this->actingAs($pengamat)->get(route('pos.index'))->assertOk();
        $this->actingAs($pengamat)->post(route('pos.buka_shift'), ['kode_lokasi' => 'L002', 'modal_awal' => 0])->assertForbidden();
        $this->actingAs($pengamat)->postJson(route('pos.jual'), ['bayar' => 0, 'baris' => [['kode_persediaan' => 'BRG0001', 'kuantiti' => 1]]])->assertForbidden();
    }
}
