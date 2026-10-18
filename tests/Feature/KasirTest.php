<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\Bagian;
use App\Models\BankAccount;
use App\Models\BarcodePersediaan;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\Inventory;
use App\Models\JournalLine;
use App\Models\Level;
use App\Models\LokasiPersediaan;
use App\Models\MutasiPersediaan;
use App\Models\Penjualan;
use App\Models\SatuanPersediaan;
use App\Models\User;
use App\Services\Ledger\InventoryMovement;
use App\Services\Modules\KasirService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WAFI MART TAHAP 2a — SHIFT KASIR, PENJUALAN TUNAI, JURNAL RINGKAS PER SHIFT.
 *
 * Yang dijaga:
 *  • Harga dari master (bukan diketik kasir), barcode dus = 1 dus seharga dus.
 *  • Stok keluar FIFO di toko saat struk disimpan; stok minus DITOLAK utuh (tak ada struk setengah jadi).
 *  • Satu jurnal per shift saat ditutup: kas/pendapatan, HPP/persediaan, selisih kas — dan
 *    persediaan buku besar kembali sama dengan buku pembantunya.
 *  • Void & serah terima harus orang lain.
 */
class KasirTest extends TestCase
{
    use RefreshDatabase;

    private const PERSEDIAAN = '1.ZZKS.PRS';

    private const KAS_LACI = '1.ZZKS.LCI';

    private const KAS_PUSAT = '1.ZZKS.PST';

    private const PENDAPATAN = '4.ZZKS.JUAL';

    private const HPP = '5.ZZKS.HPP';

    private const SELISIH_KAS = '5.ZZKS.SKS';

    private const UNIT = 'ZZKSU';

    private const BAGIAN = 'BZZKS';

    private User $kasir;

    private User $spv;

    protected function setUp(): void
    {
        parent::setUp();

        CoaGroup::create(['kode_grup' => 'ZZKS', 'nama_grup' => 'Kasir']);
        foreach ([
            [self::PERSEDIAAN, 'Persediaan Toko', 'debet'], [self::KAS_LACI, 'Kas Laci Banat', 'debet'],
            [self::KAS_PUSAT, 'Kas Keuangan', 'debet'], [self::PENDAPATAN, 'Pendapatan Penjualan', 'kredit'],
            [self::HPP, 'HPP', 'debet'], [self::SELISIH_KAS, 'Selisih Kas', 'debet'],
        ] as [$k, $n, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => 'ZZKS', 'jenis_saldo' => $s]);
        }
        BankAccount::create(['kode_coa' => self::KAS_LACI, 'nama_rekening' => 'Laci Banat', 'jenis_rekening' => 'tunai', 'status' => 'aktif']);
        BankAccount::create(['kode_coa' => self::KAS_PUSAT, 'nama_rekening' => 'Kas Keuangan', 'jenis_rekening' => 'tunai', 'status' => 'aktif']);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Wafi Mart']);
        Bagian::create(['kode_bagian' => self::BAGIAN, 'nama_bagian' => 'Koperasi', 'level' => 3]);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Staf', 'max_transaksi' => null]);

        LokasiPersediaan::create([
            'kode' => 'L002', 'nama' => 'Koperasi Banat', 'jenis' => 'toko', 'kode_unit' => self::UNIT, 'status' => 'aktif',
            'kode_coa_kas' => self::KAS_LACI, 'kode_coa_pendapatan' => self::PENDAPATAN, 'kode_coa_hpp' => self::HPP,
            'kode_coa_selisih_kas' => self::SELISIH_KAS, 'kode_bagian' => self::BAGIAN,
        ]);

        Inventory::create([
            'kode_persediaan' => 'BRG0001', 'nama_persediaan' => 'Teh Kotak', 'satuan' => 'pcs', 'harga_perolehan' => '0',
            'harga_jual' => '4000', 'dijual_di_toko' => true, 'kode_coa' => self::PERSEDIAAN, 'status' => 'aktif',
        ]);
        SatuanPersediaan::create(['kode_persediaan' => 'BRG0001', 'satuan' => 'DUS', 'isi' => '24', 'harga_jual' => '90000']);
        BarcodePersediaan::create(['barcode' => '0089686010015', 'kode_persediaan' => 'BRG0001']);
        BarcodePersediaan::create(['barcode' => '8998009010231', 'kode_persediaan' => 'BRG0001', 'satuan' => 'DUS']);

        // 48 pcs @3.000 di toko.
        InventoryMovement::masuk(['kode_persediaan' => 'BRG0001', 'kode_lokasi' => 'L002', 'kuantiti' => '48', 'nilai' => '144000',
            'tanggal' => now()->toDateString(), 'sumber_modul' => 'JurnalUmum', 'sumber_ref' => 'AWAL']);

        $this->kasir = $this->pengguna('kasir1');
        $this->spv = $this->pengguna('spv1');
    }

    private function pengguna(string $u, bool $admin = false): User
    {
        return User::create(['username' => $u, 'nama' => ucfirst($u), 'password_hash' => 'x', 'kode_level' => 'L1',
            'is_admin' => $admin, 'status' => 'aktif'])->fresh();
    }

    private function layanan(): KasirService
    {
        return new KasirService;
    }

    private function saldo(string $coa): string
    {
        $l = JournalLine::where('kode_coa', $coa)->selectRaw('COALESCE(SUM(debet),0) d, COALESCE(SUM(kredit),0) k')->first();

        return Money::sub($l->d, $l->k);
    }

    // ---- Buka shift ----

    public function test_shift_hanya_di_toko_berakun_lengkap_dan_satu_per_kasir(): void
    {
        try {
            $this->layanan()->bukaShift('L001', 0, $this->kasir->id_pengguna);
            $this->fail('Gudang seharusnya tak bisa dibuka shift.');
        } catch (AppException $e) {
            $this->assertStringContainsString('berjenis toko', $e->getMessage());
        }

        LokasiPersediaan::create(['kode' => 'L003', 'nama' => 'Koperasi Banin', 'jenis' => 'toko', 'status' => 'aktif']);
        try {
            $this->layanan()->bukaShift('L003', 0, $this->kasir->id_pengguna);
            $this->fail('Toko tanpa akun seharusnya ditolak.');
        } catch (AppException $e) {
            $this->assertStringContainsString('Kas Laci, Pendapatan Penjualan', $e->getMessage());
        }

        $shift = $this->layanan()->bukaShift('L002', 200000, $this->kasir->id_pengguna);
        $this->assertSame(['buka', '200000.00'], [$shift->status, $shift->modal_awal]);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/masih punya shift terbuka/');
        $this->layanan()->bukaShift('L002', 0, $this->kasir->id_pengguna);
    }

    // ---- Penjualan ----

    public function test_jual_lewat_barcode_dus_dan_pcs_memakai_harga_master(): void
    {
        $shift = $this->layanan()->bukaShift('L002', 0, $this->kasir->id_pengguna);

        $jual = $this->layanan()->jual($shift->id_shift, [
            ['barcode' => '8998009010231', 'kuantiti' => 1],       // 1 DUS @90.000
            ['barcode' => '0089686010015', 'kuantiti' => 3],       // 3 pcs @4.000
        ], 110000, $this->kasir->id_pengguna);

        $this->assertSame('JL-'.now()->format('ymd').'-0001', $jual->nomor);
        $this->assertSame(['102000.00', '8000.00', '81000.00'], [$jual->total, $jual->kembalian, $jual->hpp]);   // 27 × 3.000
        $this->assertSame([['DUS', '24.0000', '90000.00'], ['pcs', '3.0000', '4000.00']],
            $jual->details->map(fn ($d) => [$d->satuan, $d->kuantiti, $d->harga_satuan])->all());
        $this->assertSame('21.0000', InventoryMovement::stok('BRG0001', 'L002'));
        $this->assertSame(0, JournalLine::count());   // jurnalnya menunggu tutup shift

        $this->assertSame('JL-'.now()->format('ymd').'-0002',
            $this->layanan()->jual($shift->id_shift, [['kode_persediaan' => 'BRG0001', 'kuantiti' => 1]], 4000, $this->kasir->id_pengguna)->nomor);
    }

    public function test_stok_kurang_ditolak_utuh_tanpa_struk_setengah_jadi(): void
    {
        $shift = $this->layanan()->bukaShift('L002', 0, $this->kasir->id_pengguna);

        try {
            $this->layanan()->jual($shift->id_shift, [
                ['barcode' => '0089686010015', 'kuantiti' => 1],
                ['barcode' => '8998009010231', 'kuantiti' => 2],   // 48 + 1 > 48
            ], 200000, $this->kasir->id_pengguna);
            $this->fail('Stok minus seharusnya ditolak.');
        } catch (AppException $e) {
            $this->assertStringContainsString('tidak cukup', $e->getMessage());
        }

        $this->assertSame(0, Penjualan::count());
        $this->assertSame('48.0000', InventoryMovement::stok('BRG0001', 'L002'));
    }

    public function test_bayar_kurang_barang_tak_dijual_dan_shift_kasir_lain_ditolak(): void
    {
        $shift = $this->layanan()->bukaShift('L002', 0, $this->kasir->id_pengguna);

        $kasus = [
            fn () => $this->layanan()->jual($shift->id_shift, [['barcode' => '0089686010015', 'kuantiti' => 2]], 7000, $this->kasir->id_pengguna),
            fn () => $this->layanan()->jual($shift->id_shift, [['barcode' => '0089686010015', 'kuantiti' => 1]], 4000, $this->spv->id_pengguna),
            function () use ($shift) {
                Inventory::whereKey('BRG0001')->update(['dijual_di_toko' => false]);

                return $this->layanan()->jual($shift->id_shift, [['barcode' => '0089686010015', 'kuantiti' => 1]], 4000, $this->kasir->id_pengguna);
            },
        ];
        foreach (['kurang dari total', 'milik kasir lain', 'belum ditandai dijual'] as $i => $pesan) {
            try {
                $kasus[$i]();
                $this->fail("Kasus '{$pesan}' seharusnya ditolak.");
            } catch (AppException $e) {
                $this->assertStringContainsString($pesan, $e->getMessage());
            }
        }
    }

    // ---- Void ----

    public function test_batal_struk_harus_orang_lain_dan_stok_kembali(): void
    {
        $shift = $this->layanan()->bukaShift('L002', 0, $this->kasir->id_pengguna);
        $jual = $this->layanan()->jual($shift->id_shift, [['barcode' => '0089686010015', 'kuantiti' => 5]], 20000, $this->kasir->id_pengguna);

        try {
            $this->layanan()->batalPenjualan($jual->id_penjualan, 'salah scan', $this->kasir->id_pengguna);
            $this->fail('Kasir seharusnya tak bisa membatalkan struknya sendiri.');
        } catch (AppException $e) {
            $this->assertStringContainsString('orang lain', $e->getMessage());
        }

        $this->layanan()->batalPenjualan($jual->id_penjualan, 'salah scan', $this->spv->id_pengguna);
        $this->assertSame('batal', $jual->fresh()->status);
        $this->assertSame('48.0000', InventoryMovement::stok('BRG0001', 'L002'));

        // Struk batal tak ikut jurnal shift.
        $tutup = $this->layanan()->tutupShift($shift->id_shift, null, 0, null, $this->kasir->id_pengguna);
        $this->assertSame(['0', '0.00'], [(string) $tutup->jumlah_transaksi, $tutup->total_tunai]);
        $this->assertNull($tutup->journal_entry_id);
    }

    // ---- Tutup shift ----

    public function test_tutup_shift_jurnal_ringkas_dan_selisih_kas(): void
    {
        $shift = $this->layanan()->bukaShift('L002', 100000, $this->kasir->id_pengguna);
        $this->layanan()->jual($shift->id_shift, [['barcode' => '8998009010231', 'kuantiti' => 1]], 100000, $this->kasir->id_pengguna);  // 90.000
        $this->layanan()->jual($shift->id_shift, [['barcode' => '0089686010015', 'kuantiti' => 2]], 10000, $this->kasir->id_pengguna);   // 8.000

        // Seharusnya 100.000 + 98.000 = 198.000; dihitung 197.500 → kurang 500.
        $tutup = $this->layanan()->tutupShift($shift->id_shift, [100000 => 1, 50000 => 1, 20000 => 2, 5000 => 1, 2000 => 1, 500 => 1], null, 'receh kurang', $this->kasir->id_pengguna);

        $this->assertSame(['tutup', 2, '98000.00', '78000.00', '197500.00', '-500.00'],
            [$tutup->status, $tutup->jumlah_transaksi, $tutup->total_tunai, $tutup->total_hpp, $tutup->uang_dihitung, $tutup->selisih]);
        // jsonb mengurutkan ulang kunci — bandingkan isinya, bukan urutannya.
        $this->assertEquals(['100000' => 1, '50000' => 1, '20000' => 2, '5000' => 1, '2000' => 1, '500' => 1], $tutup->rincian_pecahan);

        // Satu jurnal untuk seluruh shift.
        $baris = JournalLine::where('entry_id', $tutup->journal_entry_id)->get();
        $this->assertSame(1, JournalLine::distinct('entry_id')->count('entry_id'));
        $this->assertSame('97500.00', $this->saldo(self::KAS_LACI));        // 98.000 − 500
        $this->assertSame('-98000.00', $this->saldo(self::PENDAPATAN));
        $this->assertSame('78000.00', $this->saldo(self::HPP));
        $this->assertSame('500.00', $this->saldo(self::SELISIH_KAS));
        $this->assertSame([self::UNIT], $baris->where('kode_coa', self::HPP)->pluck('kode_unit')->unique()->values()->all());
        $this->assertSame([self::BAGIAN], $baris->where('kode_coa', self::HPP)->pluck('kode_bagian')->all());

        // Buku besar persediaan kembali = buku pembantu (144.000 − 78.000).
        $this->assertSame('-78000.00', $this->saldo(self::PERSEDIAAN));
        $this->assertSame('66000.00', Inventory::find('BRG0001')->nilai_persediaan);
        $this->assertSame(0, MutasiPersediaan::where('alasan', 'penjualan')->whereNull('journal_entry_id')->count());

        // Shift tertutup tak bisa dipakai berjualan lagi.
        $this->expectException(AppException::class);
        $this->layanan()->jual($shift->id_shift, [['barcode' => '0089686010015', 'kuantiti' => 1]], 4000, $this->kasir->id_pengguna);
    }

    // ---- Serah terima ----

    public function test_serah_terima_setoran_tanpa_modal_dan_harus_orang_lain(): void
    {
        $shift = $this->layanan()->bukaShift('L002', 100000, $this->kasir->id_pengguna);
        $this->layanan()->jual($shift->id_shift, [['barcode' => '8998009010231', 'kuantiti' => 1]], 90000, $this->kasir->id_pengguna);
        $this->layanan()->tutupShift($shift->id_shift, null, 190000, null, $this->kasir->id_pengguna);

        try {
            $this->layanan()->serahTerima($shift->id_shift, self::KAS_PUSAT, $this->kasir->id_pengguna);
            $this->fail('Kasir seharusnya tak bisa menerima setorannya sendiri.');
        } catch (AppException $e) {
            $this->assertStringContainsString('orang lain', $e->getMessage());
        }

        $s = $this->layanan()->serahTerima($shift->id_shift, self::KAS_PUSAT, $this->spv->id_pengguna);

        // Setoran = 190.000 − modal 100.000; modal tetap di laci.
        $this->assertSame(['diserahkan', '90000.00', $this->spv->id_pengguna], [$s->status, $s->setoran, $s->diterima_oleh]);
        $this->assertSame('90000.00', $this->saldo(self::KAS_PUSAT));
        $this->assertSame('0.00', $this->saldo(self::KAS_LACI));   // modal awal diisi di luar (Pindah Buku), jadi netonya nol

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/sudah diterima/');
        $this->layanan()->serahTerima($shift->id_shift, self::KAS_PUSAT, $this->spv->id_pengguna);
    }

    // ---- Master lokasi ----

    public function test_akun_kasir_diisi_di_master_lokasi(): void
    {
        $admin = $this->pengguna('admks', true);
        $this->actingAs($admin)->get(route('lokasi_persediaan.edit', 'L002'))->assertOk()->assertSee('Akun Kasir');

        $this->actingAs($admin)->put(route('lokasi_persediaan.update', 'L002'), [
            'nama' => 'Koperasi Banat', 'jenis' => 'toko', 'kode_unit' => self::UNIT, 'status' => 'aktif',
            'kode_coa_kas' => self::KAS_PUSAT, 'kode_coa_pendapatan' => self::PENDAPATAN, 'kode_coa_hpp' => self::HPP,
            'kode_coa_selisih_kas' => self::SELISIH_KAS, 'kode_bagian' => self::BAGIAN,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(self::KAS_PUSAT, LokasiPersediaan::find('L002')->kode_coa_kas);

        // Kas laci WAJIB rekening terdaftar di Kas & Rekening.
        $this->actingAs($admin)->put(route('lokasi_persediaan.update', 'L002'), [
            'nama' => 'Koperasi Banat', 'jenis' => 'toko', 'status' => 'aktif', 'kode_coa_kas' => self::PENDAPATAN,
        ])->assertSessionHasErrors('kode_coa_kas');
    }
}
