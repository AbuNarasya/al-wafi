<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\Gelombang;
use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
use App\Models\Level;
use App\Models\PotonganGelombang;
use App\Models\Santri;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\Modules\GelombangService;
use App\Services\Modules\WaliService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\MembuatGelombang;
use Tests\TestCase;

/**
 * MASTER GELOMBANG — identitas & waktu, terpisah dari besarannya.
 *
 * Sebelumnya nama, periode, dan masa berlaku tersimpan di TIAP baris potongan,
 * jadi terduplikasi per jenjang: memperpanjang satu gelombang berarti menyunting
 * sebanyak jumlah jenjangnya, dan yang terlewat menghasilkan gelombang yang sama
 * berperiode berbeda-beda tanpa gejala apa pun di layar.
 *
 * Yang dijaga di sini: waktu hanya ada di SATU tempat, statusnya dihitung
 * (kedaluwarsa ≠ diarsipkan), dan mengganti kode tak boleh memutus data yang
 * sudah menyebutnya.
 */
class GelombangMasterTest extends TestCase
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
        JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler', 'status' => 'aktif']);
        Jenjang::create(['kode' => 'J001', 'nama' => 'SDTQ', 'urutan' => 1, 'status' => 'aktif', 'jumlah_tingkat' => 6]);

        $this->admin = User::create(['username' => 'admgm', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif']);
    }

    // ---- Waktu di satu tempat ----

    public function test_periode_dan_masa_berlaku_tersimpan_sekali_untuk_semua_jenjang(): void
    {
        Jenjang::create(['kode' => 'J002', 'nama' => 'SMP', 'urutan' => 2, 'status' => 'aktif', 'jumlah_tingkat' => 3]);

        $g = $this->buatGelombang(self::TA, 'G1', [
            'berlaku_mulai' => '2026-01-01', 'berlaku_sampai' => '2026-02-28', 'masa_berlaku_hari' => 14,
        ]);
        $this->svc->simpanGrid(self::TA, ['G1' => ['J001' => '5000000', 'J002' => '6000000']]);

        // Dua sel, satu periode — tak ada tempat lain untuk menyimpannya berbeda.
        $this->assertSame(2, PotonganGelombang::where('gelombang', 'G1')->count());
        $this->assertSame(1, Gelombang::where('kode', 'G1')->count());
        $this->assertSame(14, $g->refresh()->masa_berlaku_hari);
    }

    // ---- Keadaan dihitung, bukan disimpan ----

    public function test_keadaan_membedakan_kedaluwarsa_dari_arsip(): void
    {
        $berjalan = $this->buatGelombang(self::TA, 'G1', ['berlaku_mulai' => '2026-01-01', 'berlaku_sampai' => '2026-02-28']);
        $arsip = $this->buatGelombang(self::TA, 'G2', ['status' => 'arsip']);

        $this->assertSame('berlaku', $berjalan->keadaan('2026-02-01'));
        $this->assertSame('belum_mulai', $berjalan->keadaan('2025-12-31'));
        $this->assertSame('kedaluwarsa', $berjalan->keadaan('2026-03-01'));
        // Arsip = keputusan orang; tak bisa "diperpanjang" seperti kedaluwarsa.
        $this->assertSame('arsip', $arsip->keadaan('2026-02-01'));
    }

    public function test_memperpanjang_periode_menghidupkan_kembali(): void
    {
        Carbon::setTestNow('2026-03-05');
        $g = $this->buatGelombang(self::TA, 'G1', ['berlaku_mulai' => '2026-01-01', 'berlaku_sampai' => '2026-02-28']);
        $this->assertSame('kedaluwarsa', $g->keadaan());

        $this->svc->simpanMaster([
            'tahun_ajaran' => self::TA, 'kode' => 'G1', 'nama' => 'Gelombang 1',
            'berlaku_mulai' => '2026-01-01', 'berlaku_sampai' => '2026-03-31',
            'masa_berlaku_hari' => 7, 'status' => 'aktif',
        ], $g->id);

        $this->assertSame('berlaku', $g->refresh()->keadaan());
        Carbon::setTestNow();
    }

    // ---- Dropdown registrasi ----

    public function test_hanya_gelombang_berjalan_yang_ditawarkan_saat_registrasi(): void
    {
        $this->buatGelombang(self::TA, 'G1', ['nama' => 'Gelombang 1', 'berlaku_mulai' => '2026-01-01', 'berlaku_sampai' => '2026-02-28']);
        $this->buatGelombang(self::TA, 'G2', ['nama' => 'Gelombang 2', 'berlaku_mulai' => '2026-03-01', 'berlaku_sampai' => '2026-04-30']);
        $this->buatGelombang(self::TA, 'LAMA', ['nama' => 'Gelombang Lama', 'status' => 'arsip']);

        $opsi = $this->svc->opsiRegistrasi(self::TA, '2026-03-15');

        $this->assertSame(['G2' => 'Gelombang 2'], $opsi, 'yang kedaluwarsa & arsip tak ditawarkan');
    }

    /** Gelombang tanpa potongan tetap boleh dipilih — ia ada karena dibuka panitia. */
    public function test_gelombang_tanpa_potongan_tetap_ditawarkan(): void
    {
        $this->buatGelombang(self::TA, 'KHUSUS', ['nama' => 'Jalur Khusus']);

        $this->assertSame(0, PotonganGelombang::where('gelombang', 'KHUSUS')->count());
        $this->assertArrayHasKey('KHUSUS', $this->svc->opsiRegistrasi(self::TA));
    }

    // ---- Penjagaan ----

    public function test_kode_ganda_dalam_satu_tahun_ajaran_ditolak(): void
    {
        $this->buatGelombang(self::TA, 'G1');

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/sudah ada/');
        $this->svc->simpanMaster([
            'tahun_ajaran' => self::TA, 'kode' => 'G1', 'nama' => 'Duplikat',
            'berlaku_mulai' => null, 'berlaku_sampai' => null, 'masa_berlaku_hari' => 7, 'status' => 'aktif',
        ]);
    }

    public function test_kode_sama_di_tahun_ajaran_berbeda_boleh(): void
    {
        TahunAjaran::create(['kode' => '2027/2028', 'status' => 'aktif']);
        $this->buatGelombang(self::TA, 'G1');
        $this->buatGelombang('2027/2028', 'G1');

        $this->assertSame(2, Gelombang::where('kode', 'G1')->count());
    }

    public function test_periode_terbalik_ditolak(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/mendahului tanggal mulai/');
        $this->svc->simpanMaster([
            'tahun_ajaran' => self::TA, 'kode' => 'G1', 'nama' => 'G1',
            'berlaku_mulai' => '2026-03-01', 'berlaku_sampai' => '2026-01-01',
            'masa_berlaku_hari' => 7, 'status' => 'aktif',
        ]);
    }

    /** Mengganti kode tak boleh memutus sel matriks & data santri yang menyebutnya. */
    public function test_mengganti_kode_ikut_mengalihkan_perujuknya(): void
    {
        $g = $this->buatPotonganGelombang(self::TA, 'G1', 'J001', '5000000');
        $santri = $this->buatSantri('G1');

        $this->svc->simpanMaster([
            'tahun_ajaran' => self::TA, 'kode' => 'GEL-1', 'nama' => 'Gelombang 1',
            'berlaku_mulai' => null, 'berlaku_sampai' => null, 'masa_berlaku_hari' => 7, 'status' => 'aktif',
        ], Gelombang::where('kode', 'G1')->value('id'));

        $this->assertSame('GEL-1', $g->refresh()->gelombang, 'sel matriks ikut beralih');
        $this->assertSame('GEL-1', $santri->refresh()->gelombang, 'data santri ikut beralih');
    }

    /** Gelombang yang masih dipakai santri tak boleh dihapus — diarsipkan saja. */
    public function test_gelombang_terpakai_tidak_bisa_dihapus(): void
    {
        $this->buatGelombang(self::TA, 'G1');
        $this->buatSantri('G1');

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/masih dipakai/');
        $this->svc->hapus(Gelombang::where('kode', 'G1')->value('id'));
    }

    public function test_menghapus_gelombang_ikut_membuang_sel_matriksnya(): void
    {
        $this->buatPotonganGelombang(self::TA, 'G1', 'J001', '5000000');

        $this->svc->hapus(Gelombang::where('kode', 'G1')->value('id'));

        $this->assertSame(0, PotonganGelombang::where('gelombang', 'G1')->count());
    }

    // ---- Layar ----

    public function test_daftar_dan_form_terbuka(): void
    {
        $this->buatGelombang(self::TA, 'G1', ['nama' => 'Gelombang 1', 'berlaku_sampai' => '2026-02-28']);

        $this->actingAs($this->admin)->get(route('gelombang.index', ['ta' => self::TA]))
            ->assertOk()->assertSee('Gelombang 1')->assertSee('Masa Berlaku');

        $this->actingAs($this->admin)->get(route('gelombang.create', ['ta' => self::TA]))
            ->assertOk()->assertSee('Kode Gelombang');
    }

    public function test_menyimpan_lewat_layar(): void
    {
        $this->actingAs($this->admin)->post(route('gelombang.store'), [
            'tahun_ajaran' => self::TA, 'kode' => 'TAHFIZH', 'nama' => 'Beasiswa Tahfizh',
            'berlaku_mulai' => '2026-01-01', 'berlaku_sampai' => '2026-06-30',
            'masa_berlaku_hari' => 30, 'status' => 'aktif',
        ])->assertRedirect();

        $g = Gelombang::where('kode', 'TAHFIZH')->firstOrFail();
        $this->assertSame('Beasiswa Tahfizh', $g->nama);
        $this->assertSame(30, $g->masa_berlaku_hari);
    }

    private function buatSantri(string $gelombang): Santri
    {
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '08'.random_int(100000, 999999)]);

        return Santri::create([
            'no_pendaftaran' => 'P'.random_int(1000, 9999), 'nama' => 'Ahmad', 'jenis_kelamin' => 'L',
            'id_wali' => $wali->id, 'status' => 'calon', 'tahun_ajaran' => self::TA,
            'jalur' => 'reguler', 'kode_jenjang' => 'J001', 'tingkat' => 1, 'gelombang' => $gelombang,
        ]);
    }
}
