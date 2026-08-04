<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\ApprovalFlow;
use App\Models\ApprovalInstance;
use App\Models\Bagian;
use App\Models\Level;
use App\Models\LevelPengajuan;
use App\Models\User;
use App\Services\Modules\ApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TAHAP BER-SCOPE `induk` — atasan yang MEMBAWAHI pemohon, bukan semua
 * pemegang peringkat itu.
 *
 * Sebelumnya tahap "Mudir Umum" ber-scope `yayasan`: satu pengajuan Divisi
 * Keuangan tampak menunggu di EMPAT mudir sekaligus, dan keempatnya benar-benar
 * bisa menyetujui — termasuk mudir direktorat lain yang tak membawahi
 * pemohonnya. Persetujuan yang bisa diberikan orang yang salah sama saja dengan
 * tak ada persetujuan.
 *
 * Yang dijaga: daftar kandidat menyempit ke jalur induk, DAN penegakannya ikut
 * berlaku di approve()/reject() — daftar yang rapi di layar tak berarti apa-apa
 * bila rutenya masih menerima siapa saja.
 */
class PenyetujuIndukBagianTest extends TestCase
{
    use RefreshDatabase;

    private const DOK = 'UjiIndukBagian';

    private ApprovalService $svc;

    private User $mudirKauangan;   // DIR-KAU — induk DIV-KEU

    private User $mudirOperasional; // DIR-OGA — direktorat lain

    private User $pengurusYayasan;  // MGT — peringkat 2 tapi tak membawahi siapa pun

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        ApprovalService::resetRegistry();
        $this->svc = new ApprovalService;

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        LevelPengajuan::create(['peringkat' => 2, 'nama' => 'Mudir Direktorat']);
        LevelPengajuan::create(['peringkat' => 4, 'nama' => 'Staff']);

        // Struktur nyata: Yayasan → Direktorat → Divisi.
        Bagian::create(['kode_bagian' => 'YYS', 'nama_bagian' => 'Yayasan', 'level' => 1]);
        Bagian::create(['kode_bagian' => 'DIR-KAU', 'nama_bagian' => 'Direktorat Keuangan', 'kode_induk' => 'YYS', 'level' => 2]);
        Bagian::create(['kode_bagian' => 'DIR-OGA', 'nama_bagian' => 'Direktorat Operasional', 'kode_induk' => 'YYS', 'level' => 2]);
        Bagian::create(['kode_bagian' => 'MGT', 'nama_bagian' => 'Manajemen Yayasan', 'kode_induk' => 'YYS', 'level' => 2]);
        Bagian::create(['kode_bagian' => 'DIV-KEU', 'nama_bagian' => 'Divisi Keuangan', 'kode_induk' => 'DIR-KAU', 'level' => 3]);

        $this->mudirKauangan = $this->pengguna('mudirkau', 'Mudir Keuangan', 'DIR-KAU', 2);
        $this->mudirOperasional = $this->pengguna('mudiroga', 'Mudir Operasional', 'DIR-OGA', 2);
        $this->pengurusYayasan = $this->pengguna('pengurus', 'Pengurus Inti', 'MGT', 2);
        $this->staff = $this->pengguna('staff', 'Staff Keuangan', 'DIV-KEU', 4);

        $flow = ApprovalFlow::create(['kode_flow' => 'UJI-INDUK', 'nama_flow' => 'Uji', 'jenis_dokumen' => self::DOK]);
        $flow->steps()->create(['urutan' => 1, 'nama_tahap' => 'Mudir Umum', 'peringkat' => 2, 'scope' => 'induk']);
    }

    private function pengguna(string $username, string $nama, string $bagian, int $peringkat): User
    {
        return User::create(['username' => $username, 'nama' => $nama, 'password_hash' => 'x',
            'kode_level' => 'L1', 'kode_bagian' => $bagian, 'peringkat_pengajuan' => $peringkat, 'status' => 'aktif']);
    }

    private function ajukan(string $kodeBagian = 'DIV-KEU'): ApprovalInstance
    {
        $this->svc->submit([
            'jenis_dokumen' => self::DOK, 'id_dokumen' => '1', 'kode_bagian' => $kodeBagian,
            'nominal' => '1000000', 'id_pemohon' => $this->staff->id_pengguna,
        ]);

        return ApprovalInstance::where('jenis_dokumen', self::DOK)->firstOrFail();
    }

    /** Inti keluhannya: empat mudir jadi satu — yang membawahi pemohonnya. */
    public function test_kandidat_hanya_atasan_di_jalur_induk(): void
    {
        $this->ajukan();

        $posisi = $this->svc->posisi(self::DOK, '1');

        $this->assertSame(['Mudir Keuangan'], $posisi['kandidat']);
    }

    public function test_mudir_direktorat_lain_tidak_bisa_menyetujui(): void
    {
        $inst = $this->ajukan();

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/membawahi bagian pemohon/');
        $this->svc->approve($inst->id, $this->mudirOperasional->id_pengguna);
    }

    /**
     * Peringkatnya benar tapi bagiannya bukan induk siapa pun — dulu ia ikut
     * tampil DAN ikut bisa menyetujui.
     */
    public function test_pemegang_peringkat_di_luar_jalur_induk_ditolak(): void
    {
        $inst = $this->ajukan();

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/membawahi bagian pemohon/');
        $this->svc->approve($inst->id, $this->pengurusYayasan->id_pengguna);
    }

    public function test_atasan_yang_benar_tetap_bisa_menyetujui(): void
    {
        $inst = $this->ajukan();

        $this->svc->approve($inst->id, $this->mudirKauangan->id_pengguna);

        $this->assertSame('disetujui', $inst->refresh()->status);
    }

    /** Menolak sama menentukannya dengan menyetujui, jadi scope-nya sama. */
    public function test_penolakan_ikut_dibatasi_jalur_induk(): void
    {
        $inst = $this->ajukan();

        try {
            $this->svc->reject($inst->id, $this->mudirOperasional->id_pengguna, 'tidak setuju');
            $this->fail('mudir direktorat lain seharusnya tak bisa menolak');
        } catch (AppException $e) {
            $this->assertSame(403, $e->status);
        }

        $this->assertSame('berjalan', $inst->refresh()->status);
        $this->svc->reject($inst->id, $this->mudirKauangan->id_pengguna, 'anggaran belum ada');
        $this->assertSame('ditolak', $inst->refresh()->status);
    }

    /** Induk berjenjang: bila induk terdekat kosong, ditelusuri ke atas. */
    public function test_menelusuri_ke_atas_bila_induk_terdekat_kosong(): void
    {
        $ketuaYayasan = $this->pengguna('ketua', 'Ketua Yayasan', 'YYS', 2);
        $this->mudirKauangan->update(['status' => 'nonaktif']);

        $this->ajukan();

        $this->assertSame(['Ketua Yayasan'], $this->svc->posisi(self::DOK, '1')['kandidat']);
        $this->assertNotNull($ketuaYayasan->fresh());
    }

    /**
     * Nihil sampai akar → jangan buntu. Dokumen tanpa seorang pun penyetuju
     * diam di tempat tanpa ada yang merasa ditagih; daftar kelebaran masih jauh
     * lebih baik daripada itu.
     */
    public function test_tidak_pernah_buntu_bila_jalur_induk_kosong(): void
    {
        $this->mudirKauangan->update(['status' => 'nonaktif']);

        $this->ajukan();
        $kandidat = $this->svc->posisi(self::DOK, '1')['kandidat'];

        sort($kandidat);
        $this->assertSame(['Mudir Operasional', 'Pengurus Inti'], $kandidat);
    }

    /** Data master yang melingkar tak boleh menggantung permintaan. */
    public function test_induk_melingkar_tidak_membuat_gantung(): void
    {
        Bagian::whereKey('YYS')->update(['kode_induk' => 'DIV-KEU']);
        $this->mudirKauangan->update(['status' => 'nonaktif']);

        $this->ajukan();

        $this->assertNotEmpty($this->svc->posisi(self::DOK, '1')['kandidat']);
    }
}
