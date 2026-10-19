<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\CoaDetail;
use App\Models\CompanySettings;
use App\Models\Inventory;
use App\Models\JournalLine;
use App\Models\LokasiPersediaan;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\Ledger\InventoryMovement;
use App\Services\Modules\KasirService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Seeder instans Wafi Mart (INSTANS=wafimart): COA ritel badan usaha, dua toko siap kasir,
 * tanpa data pesantren. Seeder wajib teruji (lihat DatabaseSeederTest) — inilah yang dijalankan
 * saat database instans Wafi Mart dipasang dari nol.
 */
class WafiMartSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['instans.kode' => 'wafimart']);
    }

    public function test_seeder_wafi_mart_tanpa_data_pesantren_dan_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, TahunAjaran::count());
        $this->assertFalse(CoaDetail::where('nama_coa', 'like', '%Titipan%')->orWhere('nama_coa', 'like', '%SPP%')->exists());
        $this->assertFalse(BankAccount::where('nama_rekening', 'like', '%Midtrans%')->exists());
        $this->assertSame(1, User::where('username', 'admin')->count());

        foreach (['1.1.04.001' => 'Persediaan Barang Dagang', '2.1.01.001' => 'Hutang Usaha (Vendor)', '3.1.01.001' => 'Modal Disetor',
            '4.1.01.001' => 'Penjualan Barang Dagang', '5.1.01.001' => 'HPP Barang Dagang'] as $kode => $nama) {
            $this->assertSame($nama, CoaDetail::find($kode)?->nama_coa, $kode);
        }
        $s = CompanySettings::find(1);
        $this->assertSame(['2027-01-01', 'U001', '2.1.01.002'], [$s->periode_awal_pembukuan->toDateString(), $s->kode_unit_neraca, $s->kode_coa_grni]);
        $this->assertSame(['Koperasi Banin', 'Koperasi Banat'], LokasiPersediaan::where('jenis', 'toko')->orderBy('kode')->pluck('nama')->all());
    }

    public function test_kasir_toko_banin_langsung_bisa_dipakai(): void
    {
        $this->seed(DatabaseSeeder::class);
        $kasir = User::where('username', 'admin')->first();
        Inventory::create(['kode_persediaan' => 'BRG0001', 'nama_persediaan' => 'Air Mineral', 'satuan' => 'pcs', 'harga_perolehan' => '0',
            'harga_jual' => '5000', 'dijual_di_toko' => true, 'kode_coa' => '1.1.04.001', 'status' => 'aktif']);
        InventoryMovement::masuk(['kode_persediaan' => 'BRG0001', 'kode_lokasi' => 'L002', 'kuantiti' => '10', 'nilai' => '30000',
            'sumber_modul' => 'JurnalUmum', 'sumber_ref' => 'AWAL']);

        $k = new KasirService;
        $shift = $k->bukaShift('L002', 100000, $kasir->id_pengguna);
        $k->jual($shift->id_shift, [['kode_persediaan' => 'BRG0001', 'kuantiti' => 2]], 10000, $kasir->id_pengguna);
        $k->tutupShift($shift->id_shift, null, 110000, null, $kasir->id_pengguna);

        $saldo = fn ($coa) => (float) JournalLine::where('kode_coa', $coa)->sum('debet') - (float) JournalLine::where('kode_coa', $coa)->sum('kredit');
        $this->assertSame(10000.0, $saldo('1.1.01.011'));   // laci kasir Banin
        $this->assertSame(-10000.0, $saldo('4.1.01.001'));  // penjualan
        $this->assertSame(6000.0, $saldo('5.1.01.001'));    // HPP 2 × 3.000
        $this->assertSame('U002', JournalLine::where('kode_coa', '4.1.01.001')->value('kode_unit'));
    }
}
