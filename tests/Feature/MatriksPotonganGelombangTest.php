<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\Jenjang;
use App\Models\Level;
use App\Models\PotonganGelombang;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\Modules\GelombangService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MembuatGelombang;
use Tests\TestCase;

/**
 * MATRIKS POTONGAN: baris = gelombang, kolom = jenjang.
 *
 * Bentuknya sengaja meniru grid Tarif, dan seperti grid itu pula ia TIDAK punya
 * kolom "semua jenjang". Jenjang selalu diketahui saat menagih, jadi kolom
 * cadangan tak pernah dibutuhkan untuk menemukan potongan — yang ia lakukan
 * hanyalah memotong dari sel yang di layar tampak kosong.
 *
 * Yang dijaga: sel kosong berarti TIDAK ADA potongan (bukan potongan nol), dan
 * satu gelombang boleh berbeda potongan tiap jenjang tanpa saling meminjam.
 */
class MatriksPotonganGelombangTest extends TestCase
{
    use MembuatGelombang;
    use RefreshDatabase;

    private const TA = '2026/2027';

    private GelombangService $svc;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new GelombangService;

        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        TahunAjaran::create(['kode' => self::TA, 'status' => 'aktif', 'default_pendaftaran' => true]);
        Jenjang::create(['kode' => 'J001', 'nama' => 'SDTQ', 'urutan' => 1, 'status' => 'aktif', 'jumlah_tingkat' => 6]);
        Jenjang::create(['kode' => 'J002', 'nama' => 'SMP', 'urutan' => 2, 'status' => 'aktif', 'jumlah_tingkat' => 3]);

        $this->admin = User::create(['username' => 'admmx', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif']);
    }

    // ---- Bentuk matriks ----

    public function test_kolom_hanya_jenjang_tanpa_kolom_semua_jenjang(): void
    {
        $this->buatGelombang(self::TA, 'G1');

        $grid = $this->svc->grid(self::TA);

        $this->assertSame(['J001', 'J002'], array_column($grid['jenjang'], 'kode'));
        $this->assertSame(['J001', 'J002'], array_keys($grid['baris'][0]['sel']),
            'tak ada kolom cadangan "semua jenjang"');
    }

    public function test_baris_hanya_gelombang_aktif_dan_arsip_disebutkan(): void
    {
        $this->buatGelombang(self::TA, 'G1', ['nama' => 'Gelombang 1']);
        $this->buatGelombang(self::TA, 'KARYAWAN', ['nama' => 'Anak Karyawan', 'status' => 'arsip']);

        $grid = $this->svc->grid(self::TA);

        $this->assertSame(['G1'], array_column($grid['baris'], 'kode'));
        // Yang diarsipkan tidak hilang begitu saja — disebut, supaya petugas tak
        // mengira gelombangnya raib.
        $this->assertSame(['Anak Karyawan'], $grid['arsip']);
    }

    // ---- Menyimpan ----

    public function test_satu_simpan_mengisi_seluruh_sel(): void
    {
        $this->buatGelombang(self::TA, 'G1');
        $this->buatGelombang(self::TA, 'G2');

        $this->svc->simpanGrid(self::TA, [
            'G1' => ['J001' => '5000000', 'J002' => '6000000'],
            'G2' => ['J001' => '3000000', 'J002' => '4000000'],
        ]);

        $this->assertSame(4, PotonganGelombang::count());
        $this->assertSame('6000000.00', (string) PotonganGelombang::where('gelombang', 'G1')
            ->where('kode_jenjang', 'J002')->value('potongan'));
    }

    /** Sel dikosongkan = TIDAK ADA potongan; barisnya dibuang, bukan disimpan nol. */
    public function test_sel_dikosongkan_menghapus_barisnya(): void
    {
        $this->buatPotonganGelombang(self::TA, 'G1', 'J001', '5000000');

        $this->svc->simpanGrid(self::TA, ['G1' => ['J001' => '']]);

        $this->assertSame(0, PotonganGelombang::count());
    }

    /** Nol adalah angka SAH: tagihan tetap terbit, hanya tanpa dipotong. */
    public function test_nol_dibedakan_dari_kosong(): void
    {
        $this->buatGelombang(self::TA, 'G1');

        $this->svc->simpanGrid(self::TA, ['G1' => ['J001' => '0', 'J002' => '']]);

        $this->assertSame('0.00', (string) PotonganGelombang::where('kode_jenjang', 'J001')->value('potongan'));
        $this->assertNull(PotonganGelombang::where('kode_jenjang', 'J002')->first());
    }

    public function test_jenjang_tidak_saling_meminjam(): void
    {
        $this->buatPotonganGelombang(self::TA, 'G1', 'J001', '5000000');

        $svc = new \App\Services\Modules\PotonganGelombangService;
        $this->assertNotNull($svc->potonganAktif('G1', 'J001', self::TA));
        // J002 tak punya selnya sendiri → tak ada potongan, bukan ikut J001.
        $this->assertNull($svc->potonganAktif('G1', 'J002', self::TA));
    }

    public function test_gelombang_asing_ditolak(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/tidak terdaftar/');
        $this->svc->simpanGrid(self::TA, ['ENTAH' => ['J001' => '1000000']]);
    }

    public function test_potongan_negatif_ditolak(): void
    {
        $this->buatGelombang(self::TA, 'G1');

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/negatif/');
        $this->svc->simpanGrid(self::TA, ['G1' => ['J001' => '-1000']]);
    }

    // ---- Layar ----

    public function test_layar_matriks_menampilkan_kolom_jenjang_saja(): void
    {
        $this->buatPotonganGelombang(self::TA, 'G1', 'J001', '5000000', ['nama' => 'Gelombang 1']);

        $this->actingAs($this->admin)->get(route('gelombang.potongan', ['ta' => self::TA]))
            ->assertOk()
            ->assertSee('Gelombang 1')
            ->assertSee('SDTQ')
            ->assertSee('SMP')
            // Tak ada kolom Umum. Diperiksa lewat NAMA KOLOM-nya, bukan frasa
            // bebas: keterangan di layar justru menyebut "semua jenjang" untuk
            // menjelaskan ketiadaannya.
            ->assertDontSee('>Umum<', false)
            ->assertDontSee('name="sel[G1][]"', false);
    }

    public function test_menyimpan_lewat_layar(): void
    {
        $this->buatGelombang(self::TA, 'G1');

        $this->actingAs($this->admin)->put(route('gelombang.potongan.simpan'), [
            'tahun_ajaran' => self::TA,
            'sel' => ['G1' => ['J001' => '5000000', 'J002' => '']],
        ])->assertRedirect();

        $this->assertSame(1, PotonganGelombang::count());
        $this->assertSame('5000000.00', (string) PotonganGelombang::first()->potongan);
    }
}
