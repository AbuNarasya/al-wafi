<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\Asset;
use App\Models\Bagian;
use App\Models\BankAccount;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JournalLine;
use App\Models\Level;
use App\Models\PelepasanAset;
use App\Models\User;
use App\Services\Modules\AssetService;
use App\Services\Modules\PelepasanAsetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PELEPASAN ASET TETAP.
 *
 * Dulu aset hanya bisa DIHAPUS dari daftar: barisnya lenyap, sementara nilai
 * perolehan dan akumulasi penyusutannya tetap utuh di buku besar — register
 * aset dan pembukuan bercerai tanpa satu pun gejala.
 *
 * Yang diuji: jurnalnya benar untuk ketiga perlakuan, penyusutan benar-benar
 * berhenti, dan pintu hapus yang lama sudah tertutup untuk aset yang nilainya
 * sudah dibukukan.
 */
class PelepasanAsetTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZPA';

    private const ASET = '1.ZZPA.AST';

    private const AKUM = '1.ZZPA.AKM';

    private const KAS = '1.ZZPA.KAS';

    private const LR = '5.ZZPA.LR';

    private const BEBAN_DEPR = '5.ZZPA.DEP';

    private const BAGIAN = 'BZZPA';

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();

        CoaGroup::create(['kode_grup' => '1', 'nama_grup' => 'Aset', 'level' => 1]);
        CoaGroup::create(['kode_grup' => '5', 'nama_grup' => 'Beban', 'level' => 1]);
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Uji', 'kode_induk' => '1', 'level' => 3]);
        CoaGroup::create(['kode_grup' => self::GRP.'B', 'nama_grup' => 'Uji Beban', 'kode_induk' => '5', 'level' => 3]);

        foreach ([
            [self::ASET, 'Aset Tetap', 'debet', self::GRP],
            [self::AKUM, 'Akumulasi Penyusutan', 'kredit', self::GRP],
            [self::KAS, 'Kas', 'debet', self::GRP],
            [self::LR, 'Laba/Rugi Pelepasan Aset', 'debet', self::GRP.'B'],
            [self::BEBAN_DEPR, 'Beban Penyusutan', 'debet', self::GRP.'B'],
        ] as [$k, $n, $s, $g]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => $g, 'jenis_saldo' => $s]);
        }

        BankAccount::create(['kode_coa' => self::KAS, 'nama_rekening' => 'Kas Uji', 'jenis_rekening' => 'tunai', 'status' => 'aktif']);
        Bagian::create(['kode_bagian' => self::BAGIAN, 'nama_bagian' => 'Umum', 'level' => 3]);
        Level::create(['kode_level' => 'LPA', 'nama_level' => 'Admin', 'max_transaksi' => null]);

        $this->admin = User::create([
            'username' => 'admpa', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'LPA', 'is_admin' => true, 'status' => 'aktif',
        ])->id_pengguna;
    }

    private function aset(string $kode = 'AST001', string $perolehan = '100000000', string $akumulasi = '0'): Asset
    {
        return Asset::create([
            'kode_aset' => $kode, 'nama_aset' => 'Mobil Operasional', 'kuantiti' => 1,
            'harga_perolehan' => $perolehan, 'tanggal_perolehan' => '2024-01-01',
            'umur_manfaat' => 60, 'nilai_residu' => '0', 'akumulasi_depresiasi' => $akumulasi,
            'kode_coa' => self::ASET, 'status' => 'aktif',
        ]);
    }

    /** @return array<string,array{debet:string,kredit:string}> baris jurnal, dikunci akun */
    private function jurnal(PelepasanAset $dok): array
    {
        return JournalLine::where('entry_id', $dok->journal_entry_id)
            ->get()->mapWithKeys(fn ($l) => [$l->kode_coa => ['debet' => $l->debet, 'kredit' => $l->kredit]])->all();
    }

    private function dasar(string $kodeAset, array $tambahan = []): array
    {
        return array_merge([
            'kode_aset' => $kodeAset,
            'tanggal' => '2026-07-01',
            'kode_coa_akumulasi' => self::AKUM,
            'kode_coa_labarugi' => self::LR,
            'kode_bagian' => self::BAGIAN,
            'alasan' => 'Sudah tidak dipakai.',
        ], $tambahan);
    }

    // ---- Penjualan ----

    public function test_penjualan_di_atas_nilai_buku_menghasilkan_laba(): void
    {
        $this->aset('AST001', '100000000', '80000000'); // nilai buku 20jt

        $dok = (new PelepasanAsetService)->lepas($this->dasar('AST001', [
            'perlakuan' => 'dijual', 'harga_jual' => '30000000', 'kode_rekening' => self::KAS,
        ]), $this->admin);

        $this->assertSame('20000000.00', $dok->nilai_buku);
        $this->assertSame('10000000.00', $dok->laba_rugi);

        $j = $this->jurnal($dok);
        $this->assertSame('30000000.00', $j[self::KAS]['debet']);
        $this->assertSame('80000000.00', $j[self::AKUM]['debet']);
        $this->assertSame('100000000.00', $j[self::ASET]['kredit']);
        // Laba → sisi KREDIT akun laba/rugi.
        $this->assertSame('10000000.00', $j[self::LR]['kredit']);
    }

    public function test_penjualan_di_bawah_nilai_buku_menghasilkan_rugi(): void
    {
        $this->aset('AST002', '100000000', '80000000');

        $dok = (new PelepasanAsetService)->lepas($this->dasar('AST002', [
            'perlakuan' => 'dijual', 'harga_jual' => '5000000', 'kode_rekening' => self::KAS,
        ]), $this->admin);

        $this->assertSame('-15000000.00', $dok->laba_rugi);
        $this->assertSame('15000000.00', $this->jurnal($dok)[self::LR]['debet']);
    }

    public function test_penjualan_wajib_menyebut_harga_dan_rekening(): void
    {
        $this->aset('AST003');

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/Harga jual harus lebih besar dari nol/');
        (new PelepasanAsetService)->lepas($this->dasar('AST003', [
            'perlakuan' => 'dijual', 'harga_jual' => '0', 'kode_rekening' => self::KAS,
        ]), $this->admin);
    }

    // ---- Hibah & penghapusan ----

    public function test_hibah_membebankan_seluruh_nilai_buku(): void
    {
        $this->aset('AST004', '100000000', '60000000'); // nilai buku 40jt

        $dok = (new PelepasanAsetService)->lepas($this->dasar('AST004', [
            'perlakuan' => 'dihibahkan',
        ]), $this->admin);

        $this->assertSame('0.00', $dok->harga_jual);
        $this->assertSame('-40000000.00', $dok->laba_rugi);

        $j = $this->jurnal($dok);
        $this->assertArrayNotHasKey(self::KAS, $j, 'hibah tidak menerima uang, jadi tak boleh ada baris kas');
        $this->assertSame('60000000.00', $j[self::AKUM]['debet']);
        $this->assertSame('100000000.00', $j[self::ASET]['kredit']);
        $this->assertSame('40000000.00', $j[self::LR]['debet']);
    }

    public function test_aset_yang_habis_disusutkan_dihapuskan_tanpa_baris_laba_rugi(): void
    {
        // Nilai buku nol → tak ada selisih, jadi tak ada baris laba/rugi sama
        // sekali. Memaksakannya hanya melahirkan baris bernilai nol.
        $this->aset('AST005', '50000000', '50000000');

        $dok = (new PelepasanAsetService)->lepas($this->dasar('AST005', [
            'perlakuan' => 'dihapuskan',
        ]), $this->admin);

        $j = $this->jurnal($dok);
        $this->assertSame('0.00', $dok->laba_rugi);
        $this->assertArrayNotHasKey(self::LR, $j);
        $this->assertSame('50000000.00', $j[self::AKUM]['debet']);
        $this->assertSame('50000000.00', $j[self::ASET]['kredit']);
    }

    // ---- Akibat pada aset & penyusutan ----

    public function test_aset_berstatus_dilepas_dan_penyusutannya_berhenti(): void
    {
        $this->aset('AST006', '60000000', '0');

        (new PelepasanAsetService)->lepas($this->dasar('AST006', ['perlakuan' => 'dihapuskan']), $this->admin);

        $this->assertSame('dilepas', Asset::find('AST006')->status);

        // Tak ada lagi aset aktif → depresiasi tak punya yang bisa dijurnal.
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/Tidak ada nilai depresiasi/');
        (new AssetService)->runDepreciation([
            'kode_coa_beban' => self::BEBAN_DEPR, 'kode_coa_akumulasi' => self::AKUM,
        ], $this->admin);
    }

    public function test_aset_yang_sudah_dilepas_tak_bisa_dilepas_lagi(): void
    {
        $this->aset('AST007');
        (new PelepasanAsetService)->lepas($this->dasar('AST007', ['perlakuan' => 'dihapuskan']), $this->admin);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/sudah dilepas/');
        (new PelepasanAsetService)->lepas($this->dasar('AST007', ['perlakuan' => 'dihapuskan']), $this->admin);
    }

    public function test_alasan_wajib_diisi(): void
    {
        $this->aset('AST008');

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/Alasan pelepasan wajib/');
        (new PelepasanAsetService)->lepas($this->dasar('AST008', [
            'perlakuan' => 'dihapuskan', 'alasan' => '  ',
        ]), $this->admin);
    }

    // ---- Void ----

    public function test_void_membalik_jurnalnya_dan_menghidupkan_asetnya(): void
    {
        $this->aset('AST009', '100000000', '80000000');
        $dok = (new PelepasanAsetService)->lepas($this->dasar('AST009', [
            'perlakuan' => 'dijual', 'harga_jual' => '30000000', 'kode_rekening' => self::KAS,
        ]), $this->admin);

        (new PelepasanAsetService)->void($dok->id, 'Salah aset.', $this->admin, 'Admin');

        $this->assertSame('void', PelepasanAset::find($dok->id)->status);
        $this->assertSame('aktif', Asset::find('AST009')->status);

        // Jurnal + pembaliknya saling meniadakan: akun aset kembali nol mutasi.
        $mutasi = JournalLine::where('kode_coa', self::ASET)
            ->get()->reduce(fn ($s, $l) => $s + ((float) $l->debet - (float) $l->kredit), 0.0);
        $this->assertSame(0.0, $mutasi);
    }

    // ---- Pintu hapus yang lama ----

    public function test_aset_yang_sudah_disusutkan_tak_bisa_dihapus_dari_daftar(): void
    {
        $this->aset('AST010', '100000000', '10000000');

        $this->actingAs(User::find($this->admin))
            ->delete(route('assets.destroy', 'AST010'))
            ->assertRedirect();

        $this->assertNotNull(Asset::find('AST010'), 'aset yang sudah disusutkan tidak boleh lenyap dari daftar');
    }

    public function test_aset_yang_belum_tersentuh_masih_boleh_dihapus(): void
    {
        // Salah ketik yang belum menyentuh apa pun tetap boleh dibuang — kalau
        // tidak, tiap kekeliruan pengetikan jadi sampah permanen.
        $this->aset('AST011', '5000000', '0');

        $this->actingAs(User::find($this->admin))
            ->delete(route('assets.destroy', 'AST011'))
            ->assertRedirect();

        $this->assertNull(Asset::find('AST011'));
    }

    public function test_halaman_pelepasan_terbuka_dan_mencatatkan_dokumennya(): void
    {
        $this->aset('AST012', '20000000', '0');

        $this->actingAs(User::find($this->admin))
            ->post(route('assets.lepas'), $this->dasar('AST012', ['perlakuan' => 'dihibahkan']))
            ->assertRedirect(route('assets.pelepasan'));

        $this->actingAs(User::find($this->admin))
            ->get(route('assets.pelepasan'))
            ->assertOk()
            ->assertSee('Pelepasan Aset Tetap')
            ->assertSee('Mobil Operasional')
            ->assertDontSee('Belum ada aset yang dilepas');
    }
}
