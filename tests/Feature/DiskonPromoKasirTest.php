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
use App\Models\SatuanPersediaan;
use App\Models\ShiftKasir;
use App\Models\User;
use App\Services\Ledger\InventoryMovement;
use App\Services\Modules\KasirService;
use App\Services\Modules\PinPengawasService;
use App\Support\Akses;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * WAFI MART — PROMO TERJADWAL, DISKON MANUAL BER-PIN PENGAWAS (19 Okt 2026).
 */
class DiskonPromoKasirTest extends TestCase
{
    use RefreshDatabase;

    private User $kasir;

    private User $pengawas;

    private ShiftKasir $shift;

    protected function setUp(): void
    {
        parent::setUp();
        CoaGroup::create(['kode_grup' => 'ZZDP', 'nama_grup' => 'Diskon']);
        foreach ([['1.ZZDP.PRS', 'debet'], ['1.ZZDP.LCI', 'debet'], ['4.ZZDP.JUAL', 'kredit'], ['5.ZZDP.HPP', 'debet'], ['5.ZZDP.SKS', 'debet']] as [$k, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $k, 'kode_grup' => 'ZZDP', 'jenis_saldo' => $s]);
        }
        BankAccount::create(['kode_coa' => '1.ZZDP.LCI', 'nama_rekening' => 'Laci', 'jenis_rekening' => 'tunai', 'status' => 'aktif']);
        BusinessUnit::create(['kode_unit' => 'ZZDPU', 'nama_unit' => 'Wafi Mart']);
        Bagian::create(['kode_bagian' => 'BZZDP', 'nama_bagian' => 'Koperasi', 'level' => 3]);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Staf', 'max_transaksi' => null]);
        LokasiPersediaan::create([
            'kode' => 'L002', 'nama' => 'Koperasi Banat', 'jenis' => 'toko', 'kode_unit' => 'ZZDPU', 'status' => 'aktif',
            'kode_coa_kas' => '1.ZZDP.LCI', 'kode_coa_pendapatan' => '4.ZZDP.JUAL', 'kode_coa_hpp' => '5.ZZDP.HPP',
            'kode_coa_selisih_kas' => '5.ZZDP.SKS', 'kode_bagian' => 'BZZDP',
        ]);
        Inventory::create(['kode_persediaan' => 'BRG0001', 'nama_persediaan' => 'Teh Kotak', 'satuan' => 'pcs', 'harga_perolehan' => '0',
            'harga_jual' => '4000', 'dijual_di_toko' => true, 'kode_coa' => '1.ZZDP.PRS', 'status' => 'aktif']);
        SatuanPersediaan::create(['kode_persediaan' => 'BRG0001', 'satuan' => 'DUS', 'isi' => '24', 'harga_jual' => '90000']);
        InventoryMovement::masuk(['kode_persediaan' => 'BRG0001', 'kode_lokasi' => 'L002', 'kuantiti' => '100', 'nilai' => '300000',
            'sumber_modul' => 'JurnalUmum', 'sumber_ref' => 'AWAL']);

        $this->kasir = $this->pengguna('kasir', ['pos' => ['buat']]);
        $this->pengawas = $this->pengguna('spv', ['shift-kasir' => ['ubah']]);
        PinPengawas::create(['id_pengguna' => $this->pengawas->id_pengguna, 'pin_hash' => Hash::make('482915')]);
        $this->shift = (new KasirService)->bukaShift('L002', 0, $this->kasir->id_pengguna);
    }

    private function pengguna(string $u, array $hak): User
    {
        $user = User::create(['username' => $u, 'nama' => ucfirst($u), 'password_hash' => Hash::make('rahasia1'),
            'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif'])->fresh();
        foreach ($hak as $modul => $aksi) {
            HakAksesModul::create(['id_pengguna' => $user->id_pengguna, 'kode_modul' => $modul, 'lihat' => true,
                'buat' => in_array('buat', $aksi, true), 'ubah' => in_array('ubah', $aksi, true), 'hapus' => false, 'menu' => true]);
        }
        Akses::lupakan();

        return $user;
    }

    private function jual(array $baris, int $bayar, array $diskon = []): Penjualan
    {
        return (new KasirService)->jual($this->shift->id_shift, $baris, $bayar, $this->kasir->id_pengguna, $diskon);
    }

    // ---- Promo ----

    public function test_promo_berlaku_pada_rentangnya_dan_hanya_satuan_dasar(): void
    {
        Inventory::whereKey('BRG0001')->update(['harga_promo' => '3500', 'promo_mulai' => now()->subDay()->toDateString(), 'promo_selesai' => now()->addDay()->toDateString()]);

        $j = $this->jual([['kode_persediaan' => 'BRG0001', 'kuantiti' => 2], ['kode_persediaan' => 'BRG0001', 'satuan' => 'DUS', 'kuantiti' => 1]], 100000);

        $this->assertSame('97000.00', $j->total);   // 2 × 3.500 + 1 dus 90.000 (dus tak ikut promo)
        $this->assertSame([['3500.00', '4000.00', true], ['90000.00', '90000.00', false]],
            $j->details->map(fn ($d) => [$d->harga_satuan, $d->harga_normal, $d->promo])->all());
        $this->assertNull($j->disetujui_oleh);   // promo tak butuh PIN

        // Lewat masa promo → harga normal.
        Inventory::whereKey('BRG0001')->update(['promo_selesai' => now()->subDay()->toDateString()]);
        $this->assertSame('4000.00', $this->jual([['kode_persediaan' => 'BRG0001', 'kuantiti' => 1]], 4000)->details[0]->harga_satuan);
    }

    // ---- Diskon manual ----

    public function test_diskon_baris_dan_struk_dengan_pin_pengawas_dan_jurnal_bersih(): void
    {
        $j = $this->jual([['kode_persediaan' => 'BRG0001', 'kuantiti' => 5, 'diskon' => 2000]], 20000, [
            'struk' => 1000, 'alasan' => 'kemasan penyok', 'id_pengawas' => $this->pengawas->id_pengguna, 'pin' => '482915',
        ]);

        $this->assertSame(['17000.00', '1000.00', '2000.00', '18000.00'], [$j->total, $j->diskon_struk, $j->details[0]->diskon, $j->details[0]->total]);
        $this->assertSame([$this->pengawas->id_pengguna, 'kemasan penyok'], [$j->disetujui_oleh, $j->alasan_diskon]);

        // Pendapatan dicatat BERSIH (keputusan user): 17.000, bukan 20.000.
        (new KasirService)->tutupShift($this->shift->id_shift, null, 17000, null, $this->kasir->id_pengguna);
        $this->assertEquals(17000, (float) JournalLine::where('kode_coa', '4.ZZDP.JUAL')->sum('kredit'));
        $this->assertEquals(0, (float) ShiftKasir::find($this->shift->id_shift)->selisih);
    }

    public function test_diskon_tanpa_persetujuan_sah_ditolak_utuh(): void
    {
        $baris = [['kode_persediaan' => 'BRG0001', 'kuantiti' => 1, 'diskon' => 500]];
        $p = $this->pengawas->id_pengguna;
        $kasus = [
            'Tulis alasan' => ['alasan' => '', 'id_pengawas' => $p, 'pin' => '482915'],
            'Pilih pengawas' => ['alasan' => 'x', 'id_pengawas' => null, 'pin' => '482915'],
            'PIN pengawas salah' => ['alasan' => 'x', 'id_pengawas' => $p, 'pin' => '000000'],
            // Kasir yang KEBETULAN juga pemegang hak pengawas tak boleh menyetujui dirinya sendiri.
            'kasirnya sendiri' => ['alasan' => 'x', 'id_pengawas' => $this->kasir->id_pengguna, 'pin' => '1'],
        ];
        HakAksesModul::create(['id_pengguna' => $this->kasir->id_pengguna, 'kode_modul' => 'shift-kasir', 'lihat' => true, 'buat' => false, 'ubah' => true, 'hapus' => false, 'menu' => true]);
        Akses::lupakan();

        foreach ($kasus as $pesan => $diskon) {
            try {
                $this->jual($baris, 4000, $diskon);
                $this->fail("Kasus '{$pesan}' seharusnya ditolak.");
            } catch (AppException $e) {
                $this->assertStringContainsString($pesan, $e->getMessage());
            }
        }
        // Diskon melebihi harga barangnya.
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/melebihi harganya/');
        $this->jual([['kode_persediaan' => 'BRG0001', 'kuantiti' => 1, 'diskon' => 5000]], 0, ['alasan' => 'x', 'id_pengawas' => $p, 'pin' => '482915']);
    }

    public function test_pin_terkunci_sesudah_lima_kali_salah_dan_hak_dicabut(): void
    {
        $svc = new PinPengawasService;
        for ($i = 1; $i <= 5; $i++) {
            try {
                $svc->verifikasi($this->pengawas->id_pengguna, '111111', $this->kasir->id_pengguna);
            } catch (AppException) {
            }
        }
        try {
            $svc->verifikasi($this->pengawas->id_pengguna, '482915', $this->kasir->id_pengguna);   // PIN benar pun ditolak selama terkunci
            $this->fail('PIN seharusnya terkunci.');
        } catch (AppException $e) {
            $this->assertStringContainsString('terkunci', $e->getMessage());
        }

        // Hak dicabut → PIN yang terpasang tak berguna lagi.
        PinPengawas::whereKey($this->pengawas->id_pengguna)->update(['terkunci_sampai' => null]);
        HakAksesModul::where('id_pengguna', $this->pengawas->id_pengguna)->delete();
        Akses::lupakan();
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/berhak menyetujui/');
        $svc->verifikasi($this->pengawas->id_pengguna, '482915', $this->kasir->id_pengguna);
    }

    // ---- Layar ----

    public function test_pengawas_memasang_pin_di_profil(): void
    {
        $this->actingAs($this->pengawas)->get(route('profil.index'))->assertOk()->assertSee('PIN Pengawas Kasir');
        $this->actingAs($this->kasir)->get(route('profil.index'))->assertOk()->assertDontSee('PIN Pengawas Kasir');

        $kirim = fn ($pin, $sandi = 'rahasia1') => $this->actingAs($this->pengawas)
            ->put(route('profil.pin_pengawas'), ['password_akun' => $sandi, 'pin' => $pin, 'pin_confirmation' => $pin]);

        $kirim('739204', 'salah')->assertSessionHasErrors('password_akun');
        $kirim('111111')->assertSessionHasErrors(['pin' => 'PIN terlalu mudah ditebak — hindari angka kembar atau berurutan.']);
        $kirim('12ab56')->assertSessionHasErrors('pin');
        $kirim('739204')->assertRedirect(route('profil.index'))->assertSessionHas('status');

        $this->assertTrue(Hash::check('739204', PinPengawas::find($this->pengawas->id_pengguna)->pin_hash));
    }

    public function test_layar_kasir_dan_json_diskon(): void
    {
        $this->actingAs($this->kasir)->get(route('pos.index'))->assertOk()->assertSee('Persetujuan pengawas')
            ->assertSee('<option value="'.$this->pengawas->id_pengguna.'">Spv</option>', false);

        $this->actingAs($this->kasir)->postJson(route('pos.jual'), [
            'bayar' => 4000, 'baris' => [['kode_persediaan' => 'BRG0001', 'kuantiti' => 1, 'diskon' => 500]],
            'alasan_diskon' => 'x', 'id_pengawas' => $this->pengawas->id_pengguna, 'pin' => '999999',
        ])->assertStatus(422)->assertJsonFragment(['message' => 'PIN pengawas salah.']);

        $hasil = $this->actingAs($this->kasir)->postJson(route('pos.jual'), [
            'bayar' => 4000, 'baris' => [['kode_persediaan' => 'BRG0001', 'kuantiti' => 1, 'diskon' => 500]],
            'alasan_diskon' => 'x', 'id_pengawas' => $this->pengawas->id_pengguna, 'pin' => '482915',
        ])->assertOk()->json();
        $this->assertSame([3500, 500], [(int) $hasil['total'], (int) $hasil['kembalian']]);

        $this->actingAs($this->kasir)->get($hasil['struk'])->assertOk()->assertSee('diskon')->assertSee('Diskon disetujui: Spv');
    }

    public function test_aturan_isian_promo_di_master_barang(): void
    {
        $admin = User::create(['username' => 'adm', 'nama' => 'Adm', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => true])->fresh();
        $dasar = ['nama_persediaan' => 'Teh Kotak', 'satuan' => 'pcs', 'harga_perolehan' => '0', 'dijual_di_toko' => '1', 'harga_jual' => '4000',
            'kode_coa' => '1.ZZDP.PRS', 'kode_coa_beban' => '5.ZZDP.HPP', 'kode_coa_selisih' => '5.ZZDP.SKS', 'status' => 'aktif'];

        $this->actingAs($admin)->put(route('inventory.update', 'BRG0001'), [...$dasar, 'harga_promo' => '4500', 'promo_mulai' => '2026-10-01'])
            ->assertSessionHasErrors(['harga_promo' => 'Harga promo harus lebih murah dari harga jual.']);
        $this->actingAs($admin)->put(route('inventory.update', 'BRG0001'), [...$dasar, 'harga_promo' => '3500'])
            ->assertSessionHasErrors('promo_mulai');
        $this->actingAs($admin)->put(route('inventory.update', 'BRG0001'), [...$dasar, 'harga_promo' => '3500', 'promo_mulai' => '2026-10-01', 'promo_selesai' => '2026-10-31'])
            ->assertSessionHasNoErrors();
        $this->assertSame(['3500.00', '2026-10-31'], [Inventory::find('BRG0001')->harga_promo, Inventory::find('BRG0001')->promo_selesai->toDateString()]);
        $this->assertTrue(Money::gtZero(Inventory::find('BRG0001')->harga_promo));
    }
}
