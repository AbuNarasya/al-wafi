<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\Bagian;
use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\HakAksesModul;
use App\Models\Inventory;
use App\Models\JournalLine;
use App\Models\Level;
use App\Models\LokasiPersediaan;
use App\Models\Penjualan;
use App\Models\PinPengawas;
use App\Models\ReturPenjualan;
use App\Models\SatuanPersediaan;
use App\Models\ShiftKasir;
use App\Models\User;
use App\Services\Ledger\InventoryMovement;
use App\Services\Modules\KasirService;
use App\Support\Akses;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * WAFI MART — RETUR PENJUALAN (19 Okt 2026).
 *
 * Yang dijaga:
 *  • Uang kembali = yang DIBAYAR (diskon baris & struk terbagi), sisa terakhir persis.
 *  • Layak → stok kembali seharga pokok asal; rusak → tidak kembali.
 *  • Uang keluar dari laci shift YANG SEDANG BUKA; jurnal ringkas shift membalik
 *    pendapatan & HPP (layak saja); selisih kas tetap nol bila laci benar.
 *  • Tak melebihi struk, tak lewat batas hari, PIN pengawas wajib.
 */
class ReturPenjualanTest extends TestCase
{
    use RefreshDatabase;

    private User $kasir;

    private User $pengawas;

    private KasirService $k;

    protected function setUp(): void
    {
        parent::setUp();
        CoaGroup::create(['kode_grup' => 'ZZRT', 'nama_grup' => 'Retur']);
        foreach ([['1.ZZRT.PRS', 'debet'], ['1.ZZRT.LCI', 'debet'], ['4.ZZRT.JUAL', 'kredit'], ['5.ZZRT.HPP', 'debet'], ['5.ZZRT.SKS', 'debet']] as [$kd, $s]) {
            CoaDetail::create(['kode_coa' => $kd, 'nama_coa' => $kd, 'kode_grup' => 'ZZRT', 'jenis_saldo' => $s]);
        }
        BankAccount::create(['kode_coa' => '1.ZZRT.LCI', 'nama_rekening' => 'Laci', 'jenis_rekening' => 'tunai', 'status' => 'aktif']);
        BusinessUnit::create(['kode_unit' => 'ZZRTU', 'nama_unit' => 'Wafi Mart']);
        Bagian::create(['kode_bagian' => 'BZZRT', 'nama_bagian' => 'Koperasi', 'level' => 3]);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Staf', 'max_transaksi' => null]);
        LokasiPersediaan::create([
            'kode' => 'L002', 'nama' => 'Koperasi Banat', 'jenis' => 'toko', 'kode_unit' => 'ZZRTU', 'status' => 'aktif', 'batas_hari_retur' => 7,
            'kode_coa_kas' => '1.ZZRT.LCI', 'kode_coa_pendapatan' => '4.ZZRT.JUAL', 'kode_coa_hpp' => '5.ZZRT.HPP',
            'kode_coa_selisih_kas' => '5.ZZRT.SKS', 'kode_bagian' => 'BZZRT',
        ]);
        Inventory::create(['kode_persediaan' => 'BRG0001', 'nama_persediaan' => 'Teh Kotak', 'satuan' => 'pcs', 'harga_perolehan' => '0',
            'harga_jual' => '4000', 'dijual_di_toko' => true, 'kode_coa' => '1.ZZRT.PRS', 'status' => 'aktif']);
        SatuanPersediaan::create(['kode_persediaan' => 'BRG0001', 'satuan' => 'DUS', 'isi' => '24', 'harga_jual' => '90000']);
        InventoryMovement::masuk(['kode_persediaan' => 'BRG0001', 'kode_lokasi' => 'L002', 'kuantiti' => '100', 'nilai' => '300000',
            'sumber_modul' => 'JurnalUmum', 'sumber_ref' => 'AWAL']);   // @3.000

        $this->kasir = $this->pengguna('kasir', ['pos' => ['buat']]);
        $this->pengawas = $this->pengguna('spv', ['shift-kasir' => ['ubah']]);
        PinPengawas::create(['id_pengguna' => $this->pengawas->id_pengguna, 'pin_hash' => Hash::make('482915')]);
        $this->k = new KasirService;
    }

    private function pengguna(string $u, array $hak): User
    {
        $user = User::create(['username' => $u, 'nama' => ucfirst($u), 'password_hash' => 'x', 'kode_level' => 'L1',
            'is_admin' => false, 'status' => 'aktif'])->fresh();
        foreach ($hak as $modul => $aksi) {
            HakAksesModul::create(['id_pengguna' => $user->id_pengguna, 'kode_modul' => $modul, 'lihat' => true,
                'buat' => in_array('buat', $aksi, true), 'ubah' => in_array('ubah', $aksi, true), 'hapus' => false, 'menu' => true]);
        }
        Akses::lupakan();

        return $user;
    }

    private function retur(ShiftKasir $shift, Penjualan $jual, array $baris, ?string $pin = '482915'): ReturPenjualan
    {
        return $this->k->retur($shift->id_shift, $jual->nomor, $baris, 'salah beli', $this->pengawas->id_pengguna, $pin, $this->kasir->id_pengguna);
    }

    /** Shift kemarin: 4 pcs dengan diskon struk 1.600 → dibayar 14.400 (porsi 0,9). Ditutup. */
    private function strukKemarin(): Penjualan
    {
        $kemarin = $this->k->bukaShift('L002', 0, $this->kasir->id_pengguna);
        $jual = $this->k->jual($kemarin->id_shift, [['kode_persediaan' => 'BRG0001', 'kuantiti' => 4]], 14400, $this->kasir->id_pengguna,
            ['struk' => 1600, 'alasan' => 'pelanggan tetap', 'id_pengawas' => $this->pengawas->id_pengguna, 'pin' => '482915']);
        $this->k->tutupShift($kemarin->id_shift, null, 14400, null, $this->kasir->id_pengguna);

        return $jual;
    }

    public function test_retur_uang_sesuai_yang_dibayar_stok_layak_kembali_dan_jurnal_shift_baru(): void
    {
        $jual = $this->strukKemarin();
        $hari = $this->k->bukaShift('L002', 50000, $this->kasir->id_pengguna);
        $d = $jual->details[0];

        // 1 layak + 1 rusak dari 4 pcs dibayar 14.400 → 3.600 per pcs.
        $r = $this->retur($hari, $jual, [['id_penjualan_detail' => $d->id, 'kuantiti' => 1, 'kondisi' => 'layak']]);
        $r2 = $this->retur($hari, $jual, [['id_penjualan_detail' => $d->id, 'kuantiti' => 1, 'kondisi' => 'rusak']]);
        $this->assertSame(['3600.00', '3000.00', '3600.00', '0.00'], [$r->total, $r->hpp_kembali, $r2->total, $r2->hpp_kembali]);
        $this->assertSame([$this->pengawas->id_pengguna, $hari->id_shift], [$r->disetujui_oleh, $r->id_shift]);

        // Stok: 100 − 4 + 1 (layak saja).
        $this->assertSame('97.0000', InventoryMovement::stok('BRG0001', 'L002'));

        // Sisa 2 pcs → retur terakhir memakai sisa nilai persis: 14.400 − 7.200 = 7.200.
        $r3 = $this->retur($hari, $jual, [['id_penjualan_detail' => $d->id, 'kuantiti' => 2, 'kondisi' => 'layak']]);
        $this->assertSame(['7200.00', '6000.00'], [$r3->total, $r3->hpp_kembali]);

        try {
            $this->retur($hari, $jual, [['id_penjualan_detail' => $d->id, 'kuantiti' => 1, 'kondisi' => 'layak']]);
            $this->fail('Retur melebihi struk seharusnya ditolak.');
        } catch (AppException $e) {
            $this->assertStringContainsString('melebihi yang dibeli', $e->getMessage());
        }

        // Tutup shift hari ini: laci = 50.000 − 14.400 retur.
        $tutup = $this->k->tutupShift($hari->id_shift, null, 35600, null, $this->kasir->id_pengguna);
        $this->assertSame(['14400.00', '0.00'], [$tutup->total_retur, $tutup->selisih]);

        $saldo = fn ($coa) => Money::sub(JournalLine::where('kode_coa', $coa)->sum('debet'), JournalLine::where('kode_coa', $coa)->sum('kredit'));
        $this->assertSame('0.00', $saldo('4.ZZRT.JUAL'));         // 14.400 dijual, 14.400 diretur
        $this->assertSame('0.00', $saldo('1.ZZRT.LCI'));          // masuk kemarin, keluar hari ini
        $this->assertSame('3000.00', $saldo('5.ZZRT.HPP'));       // HPP 12.000 − 9.000 layak kembali = rugi barang rusak
        $this->assertSame('-3000.00', $saldo('1.ZZRT.PRS'));      // = nilai 1 pcs yang dibuang
        $this->assertSame(Money::of(Inventory::find('BRG0001')->nilai_persediaan), Money::add('300000', $saldo('1.ZZRT.PRS')));
    }

    public function test_aturan_retur(): void
    {
        $jual = $this->strukKemarin();
        $hari = $this->k->bukaShift('L002', 0, $this->kasir->id_pengguna);
        $d = $jual->details[0];
        $baris = [['id_penjualan_detail' => $d->id, 'kuantiti' => 1, 'kondisi' => 'layak']];

        foreach ([
            'PIN pengawas salah' => fn () => $this->retur($hari, $jual, $baris, '000000'),
            'Belum ada barang' => fn () => $this->retur($hari, $jual, [['id_penjualan_detail' => $d->id, 'kuantiti' => 0, 'kondisi' => null]]),
            'lewat batas retur' => function () use ($hari, $jual, $baris) {
                Penjualan::whereKey($jual->id_penjualan)->update(['waktu' => now()->subDays(8)]);

                return $this->retur($hari, $jual->fresh(), $baris);
            },
        ] as $pesan => $aksi) {
            try {
                $aksi();
                $this->fail("Kasus '{$pesan}' seharusnya ditolak.");
            } catch (AppException $e) {
                $this->assertStringContainsString($pesan, $e->getMessage());
            }
        }
        $this->assertSame(0, ReturPenjualan::count());
    }

    public function test_struk_yang_sudah_diretur_tak_bisa_dibatalkan_utuh(): void
    {
        $shift = $this->k->bukaShift('L002', 0, $this->kasir->id_pengguna);
        $jual = $this->k->jual($shift->id_shift, [['kode_persediaan' => 'BRG0001', 'kuantiti' => 3]], 12000, $this->kasir->id_pengguna);
        $this->retur($shift, $jual, [['id_penjualan_detail' => $jual->details[0]->id, 'kuantiti' => 1, 'kondisi' => 'layak']]);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/sudah pernah diretur/');
        $this->k->batalPenjualan($jual->id_penjualan, 'x', $this->pengawas->id_pengguna);
    }

    public function test_layar_retur(): void
    {
        $jual = $this->strukKemarin();
        $this->k->bukaShift('L002', 50000, $this->kasir->id_pengguna);

        $this->actingAs($this->kasir)->get(route('pos.retur', ['struk' => $jual->nomor]))->assertOk()
            ->assertSee('Teh Kotak')->assertSee('Bisa diretur')->assertSee('<option value="'.$this->pengawas->id_pengguna.'"', false);

        $this->actingAs($this->kasir)->post(route('pos.retur.simpan'), [
            'nomor_struk' => $jual->nomor, 'alasan' => 'rasa aneh', 'id_pengawas' => $this->pengawas->id_pengguna, 'pin' => '482915',
            'baris' => [['id_penjualan_detail' => $jual->details[0]->id, 'kuantiti' => 2, 'kondisi' => 'rusak']],
        ])->assertRedirect(route('pos.index'))->assertSessionHas('status', fn ($s) => str_contains($s, 'Rp 7.200'));

        $retur = ReturPenjualan::sole();
        $this->actingAs($this->kasir)->get(route('pos.struk_retur', $retur->id_retur))->assertOk()
            ->assertSee('RETUR PENJUALAN')->assertSee($jual->nomor)->assertSee('7.200')->assertSee('rusak');
        $this->actingAs($this->pengawas)->get(route('shift_kasir.show', $retur->id_shift))->assertOk()->assertSee($retur->nomor);
    }

    // ---- Audit 21 Okt 2026 (Paket 1) ----

    public function test_retur_struk_berdiskon_mengembalikan_persis_yang_dibayar(): void
    {
        // 26 × 4.000 = 104.000, diskon struk 4.000 → dibayar 100.000 (porsi 0,9615…; dulu terpotong 0,96 → 99.840).
        $shift = $this->k->bukaShift('L002', 0, $this->kasir->id_pengguna);
        $jual = $this->k->jual($shift->id_shift, [['kode_persediaan' => 'BRG0001', 'kuantiti' => 26]], 100000, $this->kasir->id_pengguna,
            ['struk' => 4000, 'alasan' => 'promo', 'id_pengawas' => $this->pengawas->id_pengguna, 'pin' => '482915']);
        $baris = $jual->details[0]->id;

        $r1 = $this->retur($shift, $jual, [['id_penjualan_detail' => $baris, 'kuantiti' => 13, 'kondisi' => 'layak']]);
        $this->assertSame('50000.00', $r1->total);
        $r2 = $this->retur($shift, $jual, [['id_penjualan_detail' => $baris, 'kuantiti' => 13, 'kondisi' => 'layak']]);
        $this->assertSame('50000.00', $r2->total);   // total kembali = 100.000, persis yang dibayar
    }

    public function test_barang_berharga_nol_tak_bisa_dijual(): void
    {
        Inventory::create(['kode_persediaan' => 'BRG0002', 'nama_persediaan' => 'Sampo', 'satuan' => 'pcs', 'harga_perolehan' => '0',
            'harga_jual' => '0', 'dijual_di_toko' => true, 'kode_coa' => '1.ZZRT.PRS', 'status' => 'aktif']);
        InventoryMovement::masuk(['kode_persediaan' => 'BRG0002', 'kode_lokasi' => 'L002', 'kuantiti' => '5', 'nilai' => '50000',
            'sumber_modul' => 'JurnalUmum', 'sumber_ref' => 'AWAL2']);
        $shift = $this->k->bukaShift('L002', 0, $this->kasir->id_pengguna);
        try {
            $this->k->jual($shift->id_shift, [['kode_persediaan' => 'BRG0002', 'kuantiti' => 1]], 0, $this->kasir->id_pengguna);
            $this->fail('Harus ditolak.');
        } catch (AppException $e) {
            $this->assertStringContainsString('belum punya harga jual', $e->getMessage());
        }
        $this->actingAs($this->kasir)->getJson(route('pos.cari', ['q' => 'Sampo']))->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_barang_yang_punya_riwayat_stok_tak_bisa_dihapus(): void
    {
        $admin = User::create(['username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif']);
        $this->actingAs($admin)->delete(route('inventory.destroy', 'BRG0001'))->assertSessionHas('error');
        $this->assertNotNull(Inventory::find('BRG0001'));
        $this->assertGreaterThan(0, \App\Models\MutasiPersediaan::where('kode_persediaan', 'BRG0001')->count());

        Inventory::create(['kode_persediaan' => 'BRG0009', 'nama_persediaan' => 'Belum dipakai', 'satuan' => 'pcs', 'harga_perolehan' => '0', 'status' => 'aktif']);
        $this->actingAs($admin)->delete(route('inventory.destroy', 'BRG0009'))->assertSessionHasNoErrors();
        $this->assertNull(Inventory::find('BRG0009'));
    }
}
