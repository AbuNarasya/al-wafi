<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\Bagian;
use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\DompetSantri;
use App\Models\HakAksesModul;
use App\Models\Inventory;
use App\Models\Jenjang;
use App\Models\JournalLine;
use App\Models\Level;
use App\Models\LokasiPersediaan;
use App\Models\MutasiDompet;
use App\Models\Penjualan;
use App\Models\PinPengawas;
use App\Models\Santri;
use App\Models\ShiftKasir;
use App\Models\User;
use App\Models\Wali;
use App\Services\Ledger\InventoryMovement;
use App\Services\Modules\KartuSantriService;
use App\Services\Modules\KasirService;
use App\Services\Ppsb\DompetPolicy;
use App\Support\Akses;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * BELANJA KARTU DI KASIR (D2, 19 Okt 2026).
 *
 * Yang dijaga:
 *  • Saldo dompet santri terpotong saat struk disimpan; laci kasir tak tersentuh.
 *  • Sampai batas harian cukup tempel kartu; di atasnya PIN belanja (salah → ditolak, terhitung).
 *  • Saldo kurang / kartu diblokir → ditolak UTUH (stok & saldo tak berubah).
 *  • Batal & retur struk kartu mengembalikan uang ke DOMPET, dan jatah hariannya ikut pulih.
 *  • Jurnal ringkas shift: D Titipan Uang Saku / K Pendapatan (retur kartu: kebalikannya).
 */
class BayarKartuKasirTest extends TestCase
{
    use RefreshDatabase;

    private const UID = '0012345678';

    private User $kasir;

    private User $pengawas;

    private Santri $ahmad;

    private ShiftKasir $shift;

    private KasirService $k;

    protected function setUp(): void
    {
        parent::setUp();
        CoaGroup::create(['kode_grup' => 'ZZBK', 'nama_grup' => 'Kartu']);
        foreach ([['1.ZZBK.PRS', 'debet'], ['1.ZZBK.LCI', 'debet'], ['4.ZZBK.JUAL', 'kredit'], ['5.ZZBK.HPP', 'debet'], ['5.ZZBK.SKS', 'debet'],
            [DompetPolicy::COA_TITIPAN['santri'], 'kredit']] as [$kd, $s]) {
            CoaDetail::create(['kode_coa' => $kd, 'nama_coa' => $kd, 'kode_grup' => 'ZZBK', 'jenis_saldo' => $s]);
        }
        BankAccount::create(['kode_coa' => '1.ZZBK.LCI', 'nama_rekening' => 'Laci', 'jenis_rekening' => 'tunai', 'status' => 'aktif']);
        BusinessUnit::create(['kode_unit' => 'ZZBKU', 'nama_unit' => 'Wafi Mart']);
        Bagian::create(['kode_bagian' => 'BZZBK', 'nama_bagian' => 'Koperasi', 'level' => 3]);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Staf', 'max_transaksi' => null]);
        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'jumlah_tingkat' => 3]);
        LokasiPersediaan::create([
            'kode' => 'L002', 'nama' => 'Koperasi Banat', 'jenis' => 'toko', 'kode_unit' => 'ZZBKU', 'status' => 'aktif',
            'kode_coa_kas' => '1.ZZBK.LCI', 'kode_coa_pendapatan' => '4.ZZBK.JUAL', 'kode_coa_hpp' => '5.ZZBK.HPP',
            'kode_coa_selisih_kas' => '5.ZZBK.SKS', 'kode_bagian' => 'BZZBK',
        ]);
        Inventory::create(['kode_persediaan' => 'BRG0001', 'nama_persediaan' => 'Teh Kotak', 'satuan' => 'pcs', 'harga_perolehan' => '0',
            'harga_jual' => '4000', 'dijual_di_toko' => true, 'kode_coa' => '1.ZZBK.PRS', 'status' => 'aktif']);
        InventoryMovement::masuk(['kode_persediaan' => 'BRG0001', 'kode_lokasi' => 'L002', 'kuantiti' => '100', 'nilai' => '300000',
            'sumber_modul' => 'JurnalUmum', 'sumber_ref' => 'AWAL']);

        $wali = Wali::create(['kontak_utama' => 'ibu', 'nama_ibu' => 'Ibu Aminah', 'telepon_ibu' => '0811', 'nama' => 'Ibu Aminah', 'telepon' => '0811', 'status' => 'aktif']);
        $this->ahmad = Santri::create(['no_pendaftaran' => 'ZZBK-1', 'nis' => '242501001', 'nama' => 'Ahmad', 'jenis_kelamin' => 'L',
            'kode_jenjang' => 'SMP', 'jalur' => 'reguler', 'status' => 'aktif', 'id_wali' => $wali->id]);
        DompetSantri::create(['id_santri' => $this->ahmad->id, 'saldo' => 50000]);

        $this->kasir = $this->pengguna('kasir', ['pos' => ['buat']]);
        $this->pengawas = $this->pengguna('spv', ['shift-kasir' => ['ubah']]);
        PinPengawas::create(['id_pengguna' => $this->pengawas->id_pengguna, 'pin_hash' => Hash::make('482915')]);

        $kartu = new KartuSantriService;
        $kartu->pasang($this->ahmad->id, self::UID, $this->kasir->id_pengguna);
        $kartu->pasangPin($this->ahmad, '730519', '730519', $wali->id, 'ibu');

        $this->k = new KasirService;
        $this->shift = $this->k->bukaShift('L002', 100000, $this->kasir->id_pengguna);
    }

    private function pengguna(string $u, array $hak): User
    {
        $user = User::create(['username' => $u, 'nama' => ucfirst($u), 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif'])->fresh();
        foreach ($hak as $modul => $aksi) {
            HakAksesModul::create(['id_pengguna' => $user->id_pengguna, 'kode_modul' => $modul, 'lihat' => true,
                'buat' => in_array('buat', $aksi, true), 'ubah' => in_array('ubah', $aksi, true), 'hapus' => false, 'menu' => true]);
        }
        Akses::lupakan();

        return $user;
    }

    private function jualKartu(int $qty, ?string $pin = null): Penjualan
    {
        return $this->k->jual($this->shift->id_shift, [['kode_persediaan' => 'BRG0001', 'kuantiti' => $qty]], 0, $this->kasir->id_pengguna, [],
            ['uid' => self::UID, 'pin' => $pin]);
    }

    private function saldo(): string
    {
        return DompetSantri::where('id_santri', $this->ahmad->id)->value('saldo');
    }

    private function saldoAkun(string $coa): string
    {
        return Money::sub(JournalLine::where('kode_coa', $coa)->sum('debet'), JournalLine::where('kode_coa', $coa)->sum('kredit'));
    }

    public function test_belanja_di_bawah_batas_cukup_tempel_kartu_dan_jurnal_shift(): void
    {
        $j = $this->jualKartu(4);   // 16.000 ≤ batas 20.000

        $this->assertSame(['dompet', '16000.00', '16000.00', '0.00', $this->ahmad->id], [$j->metode, $j->total, $j->bayar, $j->kembalian, $j->id_santri]);
        $this->assertSame('34000.00', $this->saldo());
        $m = MutasiDompet::find($j->id_mutasi_dompet);
        $this->assertSame(['jajan', '-16000.00', '34000.00'], [$m->jenis, $m->nominal, $m->saldo_setelah]);

        // Laci tak tersentuh: uang dihitung = modal → selisih nol.
        $tutup = $this->k->tutupShift($this->shift->id_shift, null, 100000, null, $this->kasir->id_pengguna);
        $this->assertSame(['0.00', '16000.00', '0.00'], [$tutup->total_tunai, $tutup->total_dompet, $tutup->selisih]);
        $this->assertSame('16000.00', $this->saldoAkun(DompetPolicy::COA_TITIPAN['santri']));   // titipan berkurang (debet)
        $this->assertSame('-16000.00', $this->saldoAkun('4.ZZBK.JUAL'));
        $this->assertSame('0.00', $this->saldoAkun('1.ZZBK.LCI'));
        $this->assertSame($tutup->journal_entry_id, $m->fresh()->journal_entry_id);
    }

    public function test_di_atas_batas_harian_wajib_pin_santri(): void
    {
        $this->jualKartu(3);   // 12.000; sisa batas 8.000

        // Struk kedua 12.000 → total hari ini 24.000 > 20.000.
        foreach (['000000' => 'PIN belanja salah', null => 'PIN belanja salah'] as $pin => $pesan) {
            try {
                $this->jualKartu(3, $pin === '' ? null : (string) $pin);
                $this->fail('Di atas batas tanpa PIN benar seharusnya ditolak.');
            } catch (AppException $e) {
                $this->assertStringContainsString($pesan, $e->getMessage());
            }
        }
        $this->assertSame(1, Penjualan::count());
        $this->assertSame('38000.00', $this->saldo());

        $this->jualKartu(3, '730519');
        $this->assertSame('26000.00', $this->saldo());
        $this->assertSame('24000.00', KasirService::belanjaKartuHariIni($this->ahmad->id));
    }

    public function test_saldo_kurang_atau_kartu_diblokir_ditolak_utuh(): void
    {
        DompetSantri::where('id_santri', $this->ahmad->id)->update(['saldo' => 10000, 'batas_harian' => 100000]);
        try {
            $this->jualKartu(3);   // 12.000 > saldo 10.000
            $this->fail('Saldo kurang seharusnya ditolak.');
        } catch (AppException $e) {
            $this->assertStringContainsString('tidak cukup', $e->getMessage());
        }
        $this->assertSame([0, '100.0000', '10000.00'], [Penjualan::count(), InventoryMovement::stok('BRG0001', 'L002'), $this->saldo()]);

        (new KartuSantriService)->blokir($this->ahmad->kartuAktif()->first(), 'hilang', null);
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/tidak dikenal atau sudah diblokir/');
        $this->jualKartu(1);
    }

    public function test_batal_dan_retur_struk_kartu_kembali_ke_dompet(): void
    {
        $a = $this->jualKartu(2);   // 8.000
        $b = $this->jualKartu(3);   // 12.000 → saldo 30.000, jatah habis
        $this->assertSame('30000.00', $this->saldo());

        $this->k->batalPenjualan($a->id_penjualan, 'salah scan', $this->pengawas->id_pengguna);
        $this->assertSame('38000.00', $this->saldo());
        $this->assertSame('12000.00', KasirService::belanjaKartuHariIni($this->ahmad->id));   // jatah ikut pulih

        $r = $this->k->retur($this->shift->id_shift, $b->nomor, [['id_penjualan_detail' => $b->details[0]->id, 'kuantiti' => 1, 'kondisi' => 'layak']],
            'salah ambil', $this->pengawas->id_pengguna, '482915', $this->kasir->id_pengguna);
        $this->assertSame(['dompet', '4000.00'], [$r->metode, $r->total]);
        $this->assertSame('42000.00', $this->saldo());

        // Retur kartu tak keluar dari laci; jurnal: penjualan kartu 12.000 − retur 4.000 dari titipan.
        $tutup = $this->k->tutupShift($this->shift->id_shift, null, 100000, null, $this->kasir->id_pengguna);
        $this->assertSame(['0.00', '0.00'], [$tutup->total_retur, $tutup->selisih]);
        $this->assertSame('8000.00', $this->saldoAkun(DompetPolicy::COA_TITIPAN['santri']));
        $this->assertSame('-8000.00', $this->saldoAkun('4.ZZBK.JUAL'));
    }

    public function test_layar_kasir_kartu(): void
    {
        $this->actingAs($this->kasir)->get(route('pos.index'))->assertOk()->assertSee('BAYAR KARTU SANTRI');

        $this->actingAs($this->kasir)->getJson(route('pos.kartu', ['uid' => '00 1234 5678', 'total' => 24000]))->assertOk()
            ->assertJson(['nama' => 'AHMAD', 'sisa_batas' => 20000, 'perlu_pin' => true, 'punya_pin' => true])
            ->assertJsonMissingPath('saldo');
        $this->actingAs($this->kasir)->getJson(route('pos.kartu', ['uid' => '999999']))->assertNotFound();

        $hasil = $this->actingAs($this->kasir)->postJson(route('pos.jual'), [
            'bayar' => 0, 'baris' => [['kode_persediaan' => 'BRG0001', 'kuantiti' => 2]], 'kartu_uid' => self::UID,
        ])->assertOk()->json();
        $this->actingAs($this->kasir)->get($hasil['struk'])->assertOk()->assertSee('Kartu')->assertSee('AHMAD')->assertSee('Sisa uang saku')->assertSee('42.000');
    }
}
