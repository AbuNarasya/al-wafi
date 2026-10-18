<?php

namespace Tests\Feature;

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
use App\Models\ShiftKasir;
use App\Models\User;
use App\Services\Ledger\InventoryMovement;
use App\Services\Modules\KasirService;
use App\Support\Akses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WAFI MART TAHAP 2c — TUTUP SHIFT, SERAH TERIMA, BATAL STRUK, REKAP (layar).
 */
class ShiftKasirTest extends TestCase
{
    use RefreshDatabase;

    private User $kasir;

    private User $pengawas;

    protected function setUp(): void
    {
        parent::setUp();

        CoaGroup::create(['kode_grup' => 'ZZSK', 'nama_grup' => 'Shift']);
        foreach ([['1.ZZSK.PRS', 'debet'], ['1.ZZSK.LCI', 'debet'], ['1.ZZSK.PST', 'debet'], ['4.ZZSK.JUAL', 'kredit'], ['5.ZZSK.HPP', 'debet'], ['5.ZZSK.SKS', 'debet']] as [$k, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $k, 'kode_grup' => 'ZZSK', 'jenis_saldo' => $s]);
        }
        BankAccount::create(['kode_coa' => '1.ZZSK.LCI', 'nama_rekening' => 'Laci Banat', 'jenis_rekening' => 'tunai', 'status' => 'aktif']);
        BankAccount::create(['kode_coa' => '1.ZZSK.PST', 'nama_rekening' => 'Kas Keuangan', 'jenis_rekening' => 'tunai', 'status' => 'aktif']);
        BusinessUnit::create(['kode_unit' => 'ZZSKU', 'nama_unit' => 'Wafi Mart']);
        Bagian::create(['kode_bagian' => 'BZZSK', 'nama_bagian' => 'Koperasi', 'level' => 3]);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Staf', 'max_transaksi' => null]);
        LokasiPersediaan::create([
            'kode' => 'L002', 'nama' => 'Koperasi Banat', 'jenis' => 'toko', 'kode_unit' => 'ZZSKU', 'status' => 'aktif',
            'kode_coa_kas' => '1.ZZSK.LCI', 'kode_coa_pendapatan' => '4.ZZSK.JUAL', 'kode_coa_hpp' => '5.ZZSK.HPP',
            'kode_coa_selisih_kas' => '5.ZZSK.SKS', 'kode_bagian' => 'BZZSK',
        ]);
        Inventory::create(['kode_persediaan' => 'BRG0001', 'nama_persediaan' => 'Teh Kotak', 'satuan' => 'pcs', 'harga_perolehan' => '0',
            'harga_jual' => '4000', 'dijual_di_toko' => true, 'kode_coa' => '1.ZZSK.PRS', 'status' => 'aktif']);
        InventoryMovement::masuk(['kode_persediaan' => 'BRG0001', 'kode_lokasi' => 'L002', 'kuantiti' => '50', 'nilai' => '150000',
            'sumber_modul' => 'JurnalUmum', 'sumber_ref' => 'AWAL']);

        $this->kasir = $this->pengguna('kasir', ['pos' => ['buat']]);
        $this->pengawas = $this->pengguna('pengawas', ['shift-kasir' => ['ubah']]);
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

    /** Shift dengan dua struk: 5 pcs (20.000) & 2 pcs (8.000), modal 100.000. */
    private function shiftBerjualan(): ShiftKasir
    {
        $k = new KasirService;
        $shift = $k->bukaShift('L002', 100000, $this->kasir->id_pengguna);
        $k->jual($shift->id_shift, [['kode_persediaan' => 'BRG0001', 'kuantiti' => 5]], 20000, $this->kasir->id_pengguna);
        $k->jual($shift->id_shift, [['kode_persediaan' => 'BRG0001', 'kuantiti' => 2]], 10000, $this->kasir->id_pengguna);

        return $shift;
    }

    public function test_kasir_menutup_shift_hitung_buta_lalu_melihat_selisih(): void
    {
        $shift = $this->shiftBerjualan();

        // Layar tutup tak membocorkan angka seharusnya.
        $this->actingAs($this->kasir)->get(route('pos.tutup'))->assertOk()
            ->assertSee('Hitung uang di laci')->assertDontSee('Seharusnya')->assertDontSee('128.000');

        // 1×100rb + 1×20rb + 1×5rb + 1×2rb = 127.000 → kurang 1.000 dari 128.000.
        $this->actingAs($this->kasir)->post(route('pos.tutup.simpan'), [
            'pecahan' => ['100000' => 1, '50000' => '', '20000' => 1, '5000' => 1, '2000' => 1],
        ])->assertRedirect(route('pos.laporan', $shift->id_shift));

        $shift->refresh();
        $this->assertSame(['tutup', '127000.00', '-1000.00'], [$shift->status, $shift->uang_dihitung, $shift->selisih]);
        $this->actingAs($this->kasir)->get(route('pos.laporan', $shift->id_shift))->assertOk()
            ->assertSee('Seharusnya di laci')->assertSee('Kurang Rp 1.000')->assertSee('Rp 27.000');   // disetor = 127.000 − modal

        // Shift sudah ditutup → layar kasir kembali ke Buka Shift.
        $this->actingAs($this->kasir)->get(route('pos.index'))->assertOk()->assertSee('Buka Shift');
    }

    public function test_pengawas_menerima_setoran_dan_rekap_harian(): void
    {
        $shift = $this->shiftBerjualan();
        (new KasirService)->tutupShift($shift->id_shift, null, 128000, null, $this->kasir->id_pengguna);

        $this->actingAs($this->pengawas)->get(route('shift_kasir.index'))->assertOk()
            ->assertSee('1</b> shift ditutup, setorannya belum diterima', false)
            ->assertSee('Koperasi Banat')->assertSee('Rp 28.000')->assertSee('Rp 7.000');   // omzet & laba kotor (28.000 − 7×3.000)

        $this->actingAs($this->pengawas)->get(route('shift_kasir.show', $shift->id_shift))->assertOk()->assertSee('Terima setoran Rp 28.000');
        $this->actingAs($this->pengawas)->post(route('shift_kasir.serah', $shift->id_shift), ['kode_coa_kas_tujuan' => '1.ZZSK.PST'])
            ->assertRedirect()->assertSessionHas('status', 'Setoran Rp 28.000 diterima dan dijurnal.');

        $this->assertSame('diserahkan', $shift->fresh()->status);
        $this->assertEquals(28000, (float) JournalLine::where('kode_coa', '1.ZZSK.PST')->sum('debet'));

        // Pengawas boleh membuka laporan shift walau tak punya hak kasir.
        $this->actingAs($this->pengawas)->get(route('pos.laporan', $shift->id_shift))->assertOk()->assertSee('Setoran diterima Pengawas');
    }

    public function test_pengawas_membatalkan_struk_dan_kasir_tidak_bisa(): void
    {
        $shift = $this->shiftBerjualan();
        $struk = Penjualan::orderBy('id_penjualan')->first();

        $this->actingAs($this->kasir)->post(route('shift_kasir.batal_struk', $struk->id_penjualan), ['alasan' => 'x'])->assertForbidden();

        $this->actingAs($this->pengawas)->get(route('shift_kasir.show', $shift->id_shift))->assertOk()->assertSee('Batalkan');
        $this->actingAs($this->pengawas)->post(route('shift_kasir.batal_struk', $struk->id_penjualan), ['alasan' => 'salah scan'])
            ->assertRedirect()->assertSessionHas('status');

        $this->assertSame('batal', $struk->fresh()->status);
        $this->assertSame('48.0000', InventoryMovement::stok('BRG0001', 'L002'));   // 50 − 7 terjual + 5 dibatalkan
    }

    public function test_pengawas_menutup_shift_yang_terlupa_dan_akses_laporan(): void
    {
        $shift = $this->shiftBerjualan();

        $this->actingAs($this->pengawas)->post(route('shift_kasir.tutup', $shift->id_shift), ['uang_dihitung' => 128000, 'catatan' => ''])
            ->assertSessionHasErrors('catatan');
        $this->actingAs($this->pengawas)->post(route('shift_kasir.tutup', $shift->id_shift), ['uang_dihitung' => 128000, 'catatan' => 'kasir lupa'])
            ->assertRedirect();

        $shift->refresh();
        $this->assertSame(['tutup', '0.00', 'Ditutup pengawas: kasir lupa'], [$shift->status, $shift->selisih, $shift->catatan_tutup]);
        $this->assertNotNull($shift->journal_entry_id);

        // Kasir lain (bukan pemilik, tanpa hak pengawas) tak boleh membuka laporannya; menu pengawas pun tertutup.
        $lain = $this->pengguna('kasir2', ['pos' => ['buat']]);
        $this->actingAs($lain)->get(route('pos.laporan', $shift->id_shift))->assertForbidden();
        $this->actingAs($lain)->get(route('shift_kasir.index'))->assertForbidden();
    }
}
