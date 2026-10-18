<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\Bagian;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\HakAksesModul;
use App\Models\Inventory;
use App\Models\JournalLine;
use App\Models\Level;
use App\Models\LokasiPersediaan;
use App\Models\MutasiPersediaan;
use App\Models\OpnamePersediaan;
use App\Models\SatuanPersediaan;
use App\Models\TransferPersediaan;
use App\Models\User;
use App\Services\Ledger\InventoryMovement;
use App\Services\Modules\OpnamePersediaanService;
use App\Services\Modules\TransferPersediaanService;
use App\Support\Akses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WAFI MART TAHAP 1c — TRANSFER DUA LANGKAH & OPNAME BERDOKUMEN.
 *
 * Yang dijaga:
 *  • Transfer tak berjurnal & tak mengubah nilai total; harga pokok asal terbawa.
 *  • Barang beberapa transfer di "Dalam Perjalanan" tak saling memakan harga pokok.
 *  • Selisih tertahan sampai diputus ORANG LAIN; "hilang" berjurnal ke Selisih Persediaan.
 *  • Opname: tercatat dipotret saat dihitung, disetujui orang lain, baru menggerakkan stok.
 */
class TransferOpnamePersediaanTest extends TestCase
{
    use RefreshDatabase;

    private const PERSEDIAAN = '1.ZZTO.PRS';

    private const BEBAN = '5.ZZTO.BBN';

    private const SELISIH = '5.ZZTO.SLS';

    private const UNIT_GUDANG = 'ZZTOG';

    private const UNIT_TOKO = 'ZZTOT';

    private const BAGIAN = 'BZZTO';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        CoaGroup::create(['kode_grup' => 'ZZTO', 'nama_grup' => 'Transfer']);
        foreach ([[self::PERSEDIAAN, 'Persediaan', 'debet'], [self::BEBAN, 'Beban', 'debet'], [self::SELISIH, 'Selisih Persediaan', 'debet']] as [$k, $n, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => 'ZZTO', 'jenis_saldo' => $s]);
        }
        BusinessUnit::create(['kode_unit' => self::UNIT_GUDANG, 'nama_unit' => 'Gudang Pusat']);
        BusinessUnit::create(['kode_unit' => self::UNIT_TOKO, 'nama_unit' => 'Wafi Mart']);
        Bagian::create(['kode_bagian' => self::BAGIAN, 'nama_bagian' => 'Koperasi', 'level' => 3]);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Staf', 'max_transaksi' => null]);
        LokasiPersediaan::whereKey('L001')->update(['kode_unit' => self::UNIT_GUDANG]);
        LokasiPersediaan::create(['kode' => 'L002', 'nama' => 'Koperasi Banat', 'jenis' => 'toko', 'kode_unit' => self::UNIT_TOKO, 'status' => 'aktif']);

        Inventory::create([
            'kode_persediaan' => 'BRG0001', 'nama_persediaan' => 'Teh Kotak', 'satuan' => 'pcs', 'harga_perolehan' => '0',
            'kode_coa' => self::PERSEDIAAN, 'kode_coa_beban' => self::BEBAN, 'kode_coa_selisih' => self::SELISIH, 'status' => 'aktif',
        ]);
        SatuanPersediaan::create(['kode_persediaan' => 'BRG0001', 'satuan' => 'DUS', 'isi' => '24']);

        $this->admin = $this->pengguna('admto', true);
    }

    private function pengguna(string $username, bool $admin = false, array $hak = []): User
    {
        $u = User::create([
            'username' => $username, 'nama' => ucfirst($username), 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => $admin, 'status' => 'aktif',
        ])->fresh();
        foreach ($hak as $modul => $aksi) {
            HakAksesModul::create(['id_pengguna' => $u->id_pengguna, 'kode_modul' => $modul, 'lihat' => true,
                'buat' => in_array('buat', $aksi, true), 'ubah' => in_array('ubah', $aksi, true),
                'hapus' => in_array('hapus', $aksi, true), 'menu' => true]);
        }
        Akses::lupakan();

        return $u;
    }

    private function masuk(string $lokasi, string $qty, string $nilai, string $ref): void
    {
        InventoryMovement::masuk(['kode_persediaan' => 'BRG0001', 'kode_lokasi' => $lokasi, 'kuantiti' => $qty, 'nilai' => $nilai,
            'tanggal' => '2026-10-01', 'sumber_modul' => 'JurnalUmum', 'sumber_ref' => $ref]);
    }

    private function kirim(string $qty, ?string $satuan = null, ?int $oleh = null): TransferPersediaan
    {
        return (new TransferPersediaanService)->kirim([
            'tanggal_kirim' => '2026-10-10', 'kode_lokasi_asal' => 'L001', 'kode_lokasi_tujuan' => 'L002',
            'details' => [['kode_persediaan' => 'BRG0001', 'satuan' => $satuan, 'kuantiti' => $qty]],
        ], $oleh ?? $this->admin->id_pengguna);
    }

    // ---- Transfer ----

    public function test_kirim_memindah_ke_dalam_perjalanan_tanpa_jurnal(): void
    {
        $this->masuk('L001', '48', '144000', 'G-1');   // @3.000

        $doc = $this->kirim('1', 'DUS');

        $this->assertSame('TS-2610-0001', $doc->nomor);
        $this->assertSame(['24.0000', '72000.00'], [$doc->details[0]->kuantiti, $doc->details[0]->nilai]);
        $this->assertSame('24.0000', InventoryMovement::stok('BRG0001', 'L001'));
        $this->assertSame('24.0000', InventoryMovement::stok('BRG0001', LokasiPersediaan::TRANSIT));
        $this->assertSame('0.0000', InventoryMovement::stok('BRG0001', 'L002'));
        $this->assertSame('144000.00', Inventory::find('BRG0001')->nilai_persediaan);   // total tak berubah
        $this->assertSame(0, JournalLine::count());
    }

    public function test_kirim_melebihi_stok_asal_ditolak_walau_dipecah_dua_baris(): void
    {
        $this->masuk('L001', '10', '30000', 'G-1');

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/Baris 2: stok Teh Kotak di Gudang Utama tidak cukup/');
        (new TransferPersediaanService)->kirim([
            'tanggal_kirim' => '2026-10-10', 'kode_lokasi_asal' => 'L001', 'kode_lokasi_tujuan' => 'L002',
            'details' => [['kode_persediaan' => 'BRG0001', 'kuantiti' => '6'], ['kode_persediaan' => 'BRG0001', 'kuantiti' => '6']],
        ], $this->admin->id_pengguna);
    }

    public function test_terima_utuh_membawa_harga_pokok_asal_dan_hanya_sekali(): void
    {
        $this->masuk('L001', '10', '30000', 'G-1');
        $doc = $this->kirim('10');

        (new TransferPersediaanService)->terima($doc->id_transfer, '2026-10-11', [], null, $this->admin->id_pengguna);

        $this->assertSame('selesai', $doc->fresh()->status);
        $this->assertSame('10.0000', InventoryMovement::stok('BRG0001', 'L002'));
        $this->assertSame('30000.00', InventoryMovement::nilai('BRG0001', 'L002'));
        $this->assertSame('0.0000', InventoryMovement::stok('BRG0001', LokasiPersediaan::TRANSIT));

        // Kiriman yang sama tak bisa diterima dua kali (masalah transfer ganda POS lama).
        $this->expectException(AppException::class);
        (new TransferPersediaanService)->terima($doc->id_transfer, '2026-10-11', [], null, $this->admin->id_pengguna);
    }

    public function test_barang_dua_transfer_di_jalan_tak_saling_memakan_harga(): void
    {
        $this->masuk('L001', '10', '10000', 'G-1');   // @1.000 (tertua)
        $this->masuk('L001', '10', '50000', 'G-2');   // @5.000
        $a = $this->kirim('10');   // membawa lapisan @1.000
        $b = $this->kirim('10');   // membawa lapisan @5.000

        // B diterima lebih dulu: harus @5.000 miliknya sendiri, bukan lapisan TRANSIT tertua (@1.000 milik A).
        (new TransferPersediaanService)->terima($b->id_transfer, '2026-10-11', [], null, $this->admin->id_pengguna);
        $this->assertSame('50000.00', InventoryMovement::nilai('BRG0001', 'L002'));
        $this->assertSame('10000.00', InventoryMovement::nilai('BRG0001', LokasiPersediaan::TRANSIT));
        $this->assertSame('dikirim', $a->fresh()->status);
    }

    public function test_selisih_tertahan_lalu_dikembalikan_ke_asal(): void
    {
        $this->masuk('L001', '24', '72000', 'G-1');
        $doc = $this->kirim('1', 'DUS');
        $penerima = $this->pengguna('kasirbanat', false, ['transfer-persediaan' => ['ubah']]);
        $atasan = $this->pengguna('spv', false, ['persetujuan-persediaan' => ['ubah']]);

        // Dikirim 1 DUS, tiba 20 pcs — diisi dalam satuan dasar.
        (new TransferPersediaanService)->terima($doc->id_transfer, '2026-10-11', [$doc->details[0]->id => '20'], '4 pcs tak ada', $penerima->id_pengguna);
        $this->assertSame('selisih', $doc->fresh()->status);
        $this->assertSame('20.0000', InventoryMovement::stok('BRG0001', 'L002'));
        $this->assertSame('4.0000', InventoryMovement::stok('BRG0001', LokasiPersediaan::TRANSIT));

        // Penerima tak boleh memutus selisihnya sendiri.
        try {
            (new TransferPersediaanService)->putus($doc->id_transfer, 'kembali', null, null, $penerima->id_pengguna);
            $this->fail('Penerima seharusnya tak boleh memutus.');
        } catch (AppException $e) {
            $this->assertStringContainsString('orang lain', $e->getMessage());
        }

        (new TransferPersediaanService)->putus($doc->id_transfer, 'kembali', null, 'ketinggalan di gudang', $atasan->id_pengguna);
        $this->assertSame(['selesai', 'kembali'], [$doc->fresh()->status, $doc->fresh()->putusan]);
        $this->assertSame('4.0000', InventoryMovement::stok('BRG0001', 'L001'));
        $this->assertSame('0.0000', InventoryMovement::stok('BRG0001', LokasiPersediaan::TRANSIT));
        $this->assertSame(0, JournalLine::count());
    }

    public function test_selisih_diputus_hilang_berjurnal_ke_selisih_persediaan_unit_asal(): void
    {
        $this->masuk('L001', '10', '30000', 'G-1');
        $doc = $this->kirim('10');
        (new TransferPersediaanService)->terima($doc->id_transfer, '2026-10-11', [$doc->details[0]->id => '7'], null, $this->admin->id_pengguna);

        try {
            (new TransferPersediaanService)->putus($doc->id_transfer, 'hilang', null, null, $this->admin->id_pengguna);
            $this->fail('Kehilangan tanpa Bagian seharusnya ditolak.');
        } catch (AppException $e) {
            $this->assertStringContainsString('Bagian', $e->getMessage());
        }

        (new TransferPersediaanService)->putus($doc->id_transfer, 'hilang', self::BAGIAN, 'tak ditemukan', $this->admin->id_pengguna);

        $m = MutasiPersediaan::where('alasan', 'kehilangan')->sole();
        $this->assertSame(['keluar', '3.0000', '9000.00', LokasiPersediaan::TRANSIT], [$m->arah, $m->kuantiti, $m->nilai, $m->kode_lokasi]);
        $baris = JournalLine::where('entry_id', $m->journal_entry_id)->get()->keyBy('kode_coa');
        $this->assertSame(['9000.00', self::UNIT_GUDANG, self::BAGIAN], [$baris[self::SELISIH]->debet, $baris[self::SELISIH]->kode_unit, $baris[self::SELISIH]->kode_bagian]);
        $this->assertSame('9000.00', $baris[self::PERSEDIAAN]->kredit);
        $this->assertSame('21000.00', Inventory::find('BRG0001')->nilai_persediaan);
    }

    public function test_batal_kiriman_mengembalikan_lapisan_asal_persis(): void
    {
        $this->masuk('L001', '10', '10000', 'G-1');
        $this->masuk('L001', '10', '50000', 'G-2');
        $doc = $this->kirim('15');

        (new TransferPersediaanService)->batal($doc->id_transfer, 'salah tujuan', $this->admin->id_pengguna);

        $this->assertSame('batal', $doc->fresh()->status);
        $this->assertSame('20.0000', InventoryMovement::stok('BRG0001', 'L001'));
        $this->assertSame('0.0000', InventoryMovement::stok('BRG0001', LokasiPersediaan::TRANSIT));
        // Lapisan @1.000 tetap TERTUA: keluar berikutnya 10 pcs harus seharga 10.000.
        $k = InventoryMovement::keluar(['kode_persediaan' => 'BRG0001', 'kuantiti' => '10', 'sumber_modul' => 'Persediaan', 'sumber_ref' => 'MP-1']);
        $this->assertSame('10000.00', $k->nilai);
    }

    public function test_alur_layar_transfer_dan_hak_memutus(): void
    {
        $this->masuk('L001', '10', '30000', 'G-1');
        $gudang = $this->pengguna('gudang', false, ['transfer-persediaan' => ['buat', 'ubah']]);

        $this->actingAs($gudang)->get(route('transfer_persediaan.create'))->assertOk()->assertSee('Koperasi Banat');
        $this->actingAs($gudang)->post(route('transfer_persediaan.store'), [
            'tanggal_kirim' => '2026-10-10', 'kode_lokasi_asal' => 'L001', 'kode_lokasi_tujuan' => 'L002',
            'details' => [['kode_persediaan' => 'BRG0001', 'satuan' => 'pcs', 'kuantiti' => '10'], ['kode_persediaan' => '', 'kuantiti' => '']],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $doc = TransferPersediaan::with('details')->sole();

        $this->actingAs($gudang)->get(route('transfer_persediaan.show', $doc->id_transfer))->assertOk()->assertSee('Catat Penerimaan');
        $this->actingAs($gudang)->post(route('transfer_persediaan.terima', $doc->id_transfer), [
            'tanggal_terima' => '2026-10-11', 'diterima' => [$doc->details[0]->id => '9'],
        ])->assertRedirect()->assertSessionHas('status');

        // Tanpa hak persetujuan: tak ada tombol putusan, dan rutenya ditolak.
        $this->actingAs($gudang)->get(route('transfer_persediaan.show', $doc->id_transfer))->assertOk()
            ->assertSee('menunggu putusan', false)->assertDontSee('Simpan Putusan');
        $this->actingAs($gudang)->post(route('transfer_persediaan.putus', $doc->id_transfer), ['putusan' => 'kembali'])->assertForbidden();

        $this->actingAs($this->admin)->post(route('transfer_persediaan.putus', $doc->id_transfer), ['putusan' => 'kembali'])
            ->assertRedirect()->assertSessionHas('status');
        $this->assertSame('selesai', $doc->fresh()->status);

        // Lokasi sistem tak muncul di master lokasi & tak bisa disunting.
        $this->actingAs($this->admin)->get(route('lokasi_persediaan.index'))->assertOk()->assertDontSee('Dalam Perjalanan');
        $this->actingAs($this->admin)->get(route('lokasi_persediaan.edit', LokasiPersediaan::TRANSIT))->assertNotFound();
    }

    // ---- Opname ----

    private function lembar(array $tambahan = [], ?int $oleh = null): OpnamePersediaan
    {
        return (new OpnamePersediaanService)->buat([
            'tanggal' => '2026-10-15', 'kode_lokasi' => 'L002', 'cakupan' => 'pernah', 'kode_bagian' => self::BAGIAN, ...$tambahan,
        ], $oleh ?? $this->admin->id_pengguna);
    }

    public function test_lembar_opname_memuat_barang_yang_pernah_ada_di_lokasi(): void
    {
        Inventory::create(['kode_persediaan' => 'BRG0002', 'nama_persediaan' => 'Air', 'satuan' => 'btl', 'harga_perolehan' => '0',
            'kode_coa' => self::PERSEDIAAN, 'kode_coa_selisih' => self::SELISIH, 'status' => 'aktif']);
        $this->masuk('L002', '5', '15000', 'T-1');

        $this->assertSame(['BRG0001'], $this->lembar()->details()->pluck('kode_persediaan')->all());
        $this->assertSame(['BRG0001', 'BRG0002'], $this->lembar(['cakupan' => 'semua'])->details()->orderBy('kode_persediaan')->pluck('kode_persediaan')->all());
    }

    public function test_opname_memotret_tercatat_saat_dihitung_dan_disetujui_orang_lain(): void
    {
        $this->masuk('L002', '20', '60000', 'T-1');   // @3.000
        $penghitung = $this->pengguna('penghitung', false, ['opname-persediaan' => ['buat']]);
        $atasan = $this->pengguna('atasan', false, ['persetujuan-persediaan' => ['ubah']]);
        $layanan = new OpnamePersediaanService;
        $doc = $this->lembar([], $penghitung->id_pengguna);

        // Pagi: rak berisi 18 (tercatat 20 → kurang 2).
        $layanan->simpanHitungan($doc->id_opname, ['BRG0001' => '18'], null, $penghitung->id_pengguna);
        // Siang: 5 terjual. Menyimpan ulang angka yang sama TIDAK memotret ulang.
        InventoryMovement::keluar(['kode_persediaan' => 'BRG0001', 'kode_lokasi' => 'L002', 'kuantiti' => '5', 'sumber_modul' => 'Persediaan', 'sumber_ref' => 'JUAL-1']);
        $layanan->simpanHitungan($doc->id_opname, ['BRG0001' => '18'], null, $penghitung->id_pengguna);
        $this->assertSame('20.0000', $doc->details()->sole()->stok_tercatat);

        $layanan->ajukan($doc->id_opname, $penghitung->id_pengguna);

        try {
            $layanan->setujui($doc->id_opname, $penghitung->id_pengguna);
            $this->fail('Penghitung seharusnya tak boleh menyetujui sendiri.');
        } catch (AppException $e) {
            $this->assertStringContainsString('orang lain', $e->getMessage());
        }

        $layanan->setujui($doc->id_opname, $atasan->id_pengguna);

        // Selisih −2 dari POTRET pagi, bukan dari stok sore (15) → stok akhir 13.
        $this->assertSame('13.0000', InventoryMovement::stok('BRG0001', 'L002'));
        $d = $doc->details()->sole();
        $this->assertSame('-6000.00', $d->nilai_selisih);
        $baris = JournalLine::where('entry_id', MutasiPersediaan::find($d->id_mutasi)->journal_entry_id)->get()->keyBy('kode_coa');
        $this->assertSame(['6000.00', self::UNIT_TOKO, self::BAGIAN], [$baris[self::SELISIH]->debet, $baris[self::SELISIH]->kode_unit, $baris[self::SELISIH]->kode_bagian]);
        $this->assertSame('disetujui', $doc->fresh()->status);
    }

    public function test_aturan_ajukan_dan_tolak(): void
    {
        $this->masuk('L002', '10', '30000', 'T-1');
        $layanan = new OpnamePersediaanService;
        $doc = $this->lembar(['kode_bagian' => null]);

        try {
            $layanan->ajukan($doc->id_opname, $this->admin->id_pengguna);
            $this->fail('Lembar tanpa hitungan seharusnya ditolak.');
        } catch (AppException $e) {
            $this->assertStringContainsString('Belum ada', $e->getMessage());
        }

        $layanan->simpanHitungan($doc->id_opname, ['BRG0001' => '9'], null, $this->admin->id_pengguna);
        try {
            $layanan->ajukan($doc->id_opname, $this->admin->id_pengguna);
            $this->fail('Selisih tanpa Bagian seharusnya ditolak.');
        } catch (AppException $e) {
            $this->assertStringContainsString('Bagian', $e->getMessage());
        }

        $layanan->simpanHitungan($doc->id_opname, [], self::BAGIAN, $this->admin->id_pengguna);
        $layanan->ajukan($doc->id_opname, $this->admin->id_pengguna);
        $layanan->tolak($doc->id_opname, 'rak belakang belum dihitung', $this->admin->id_pengguna);

        $this->assertSame(['draft', 'Ditolak: rak belakang belum dihitung'], [$doc->fresh()->status, $doc->fresh()->catatan_putusan]);
        $this->assertSame('10.0000', InventoryMovement::stok('BRG0001', 'L002'));   // belum bergerak
    }

    public function test_layar_opname_hitung_buta_dan_simpan_json(): void
    {
        $this->masuk('L002', '10', '30000', 'T-1');
        $penghitung = $this->pengguna('hitung', false, ['opname-persediaan' => ['buat']]);

        $this->actingAs($penghitung)->post(route('opname_persediaan.store'), [
            'tanggal' => '2026-10-15', 'kode_lokasi' => 'L002', 'cakupan' => 'pernah', 'kode_bagian' => self::BAGIAN,
        ])->assertRedirect();
        $doc = OpnamePersediaan::sole();

        // Penghitung tak melihat kolom Tercatat selama draft.
        $this->actingAs($penghitung)->get(route('opname_persediaan.show', $doc->id_opname))->assertOk()
            ->assertSee('Hitung buta')->assertDontSee('>Tercatat<', false);

        $this->actingAs($penghitung)->post(route('opname_persediaan.hitung', $doc->id_opname), [
            'hitungan' => json_encode(['BRG0001' => '12']), 'kode_bagian' => self::BAGIAN, 'ajukan' => '1',
        ])->assertRedirect()->assertSessionHas('status');
        $this->assertSame(['diajukan', '12.0000'], [$doc->fresh()->status, $doc->details()->sole()->stok_fisik]);

        // Penghitung tanpa hak persetujuan tak bisa menyetujui.
        $this->actingAs($penghitung)->post(route('opname_persediaan.putus', $doc->id_opname), ['putusan' => 'setujui'])->assertForbidden();

        $this->actingAs($this->admin)->get(route('opname_persediaan.show', $doc->id_opname))->assertOk()->assertSee('Setujui');
        $this->actingAs($this->admin)->post(route('opname_persediaan.putus', $doc->id_opname), ['putusan' => 'setujui'])->assertRedirect();
        $this->assertSame('12.0000', InventoryMovement::stok('BRG0001', 'L002'));
        // Lebih 2 pcs masuk seharga rata-rata berjalan @3.000 → 12 × 3.000.
        $this->assertSame('36000.00', Inventory::find('BRG0001')->nilai_persediaan);
    }
}
