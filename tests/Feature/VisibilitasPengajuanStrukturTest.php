<?php

namespace Tests\Feature;

use App\Models\Bagian;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\HakAksesModul;
use App\Models\Level;
use App\Models\LevelPengajuan;
use App\Models\PengajuanPembayaran;
use App\Models\User;
use App\Services\Modules\StatusAnggaranPengajuanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * VISIBILITAS DAFTAR PENGAJUAN mengikuti STRUKTUR ORGANISASI.
 *
 * Aturan lama "bagian yang sama persis" membuat Ketua Yayasan (bagian YYS) tak
 * pernah melihat satu pun pengajuan — tak ada dokumen yang bagiannya YYS.
 * Ia bisa menyetujui dokumen lewat notifikasi, tapi tak pernah bisa
 * menemukannya sendiri di daftar. Atasan yang tak bisa melihat pekerjaan
 * bawahannya adalah kegagalan yang diam: tak ada pesan galat, cuma daftar
 * kosong yang tampak wajar.
 *
 * Yang dijaga sekaligus: daftar dan ringkasan dashboard memakai SATU aturan.
 * Dua layar yang menyaring sendiri-sendiri akan menyebut angka berbeda tentang
 * dokumen yang sama.
 */
class VisibilitasPengajuanStrukturTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZVS';

    private const AKUN = '5.ZZVS.1';

    private const UNIT = 'ZZVSU';

    private User $ketuaYayasan;

    private User $mudirKeuangan;

    private User $mudirOperasional;

    private User $staffKeu;

    protected function setUp(): void
    {
        parent::setUp();

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        LevelPengajuan::create(['peringkat' => 1, 'nama' => 'Ketua Yayasan']);
        LevelPengajuan::create(['peringkat' => 2, 'nama' => 'Mudir Direktorat']);
        LevelPengajuan::create(['peringkat' => 4, 'nama' => 'Staff']);

        Bagian::create(['kode_bagian' => 'YYS', 'nama_bagian' => 'Yayasan', 'level' => 1]);
        Bagian::create(['kode_bagian' => 'DIR-KAU', 'nama_bagian' => 'Direktorat Keuangan', 'kode_induk' => 'YYS', 'level' => 2]);
        Bagian::create(['kode_bagian' => 'DIR-OGA', 'nama_bagian' => 'Direktorat Operasional', 'kode_induk' => 'YYS', 'level' => 2]);
        Bagian::create(['kode_bagian' => 'DIV-KEU', 'nama_bagian' => 'Divisi Keuangan', 'kode_induk' => 'DIR-KAU', 'level' => 3]);
        Bagian::create(['kode_bagian' => 'DIV-GS', 'nama_bagian' => 'Divisi General Services', 'kode_induk' => 'DIR-OGA', 'level' => 3]);

        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Beban']);
        CoaDetail::create(['kode_coa' => self::AKUN, 'nama_coa' => 'Beban', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);

        $this->ketuaYayasan = $this->pengguna('ketua', 'Ketua Yayasan', 'YYS', 1);
        $this->mudirKeuangan = $this->pengguna('mudirkau', 'Mudir Keuangan', 'DIR-KAU', 2);
        $this->mudirOperasional = $this->pengguna('mudiroga', 'Mudir Operasional', 'DIR-OGA', 2);
        $this->staffKeu = $this->pengguna('staffkeu', 'Staff Keuangan', 'DIV-KEU', 4);

        $this->buatPengajuan('PB-KEU', 'DIV-KEU', $this->staffKeu);
        $this->buatPengajuan('PB-GS', 'DIV-GS', $this->pengguna('staffgs', 'Staff GS', 'DIV-GS', 4));
    }

    private function pengguna(string $u, string $n, ?string $bagian, ?int $peringkat): User
    {
        $user = User::create(['username' => $u, 'nama' => $n, 'password_hash' => 'x', 'kode_level' => 'L1',
            'kode_bagian' => $bagian, 'peringkat_pengajuan' => $peringkat, 'status' => 'aktif']);
        HakAksesModul::create(['id_pengguna' => $user->id_pengguna, 'kode_modul' => 'pengajuan-pembayaran',
            'lihat' => true, 'buat' => false, 'ubah' => false, 'hapus' => false, 'menu' => true]);

        return $user;
    }

    private function buatPengajuan(string $nomor, string $bagian, User $pemohon): PengajuanPembayaran
    {
        $p = PengajuanPembayaran::create([
            'nomor' => $nomor, 'tanggal' => '2026-07-10', 'jenis' => 'pembayaran', 'kode_bagian' => $bagian,
            'nominal' => '100000', 'sisa_hutang' => '0', 'keterangan' => 'uji', 'status' => 'lunas',
            'id_pengguna' => $pemohon->id_pengguna,
        ]);
        $p->details()->create(['kode_coa' => self::AKUN, 'nama_coa' => 'Beban',
            'kode_unit' => self::UNIT, 'nominal' => '100000', 'keterangan' => null]);

        return $p;
    }

    /** @return list<string> */
    private function terlihat(User $user): array
    {
        return PengajuanPembayaran::terlihatOleh($user)->orderBy('nomor')->pluck('nomor')->all();
    }

    /** Inti keluhannya: Ketua Yayasan melihat NOL padahal ia penyetuju puncak. */
    public function test_ketua_yayasan_melihat_seluruh_bawahannya(): void
    {
        $this->assertSame(['PB-GS', 'PB-KEU'], $this->terlihat($this->ketuaYayasan));
    }

    public function test_mudir_direktorat_melihat_divisinya_saja(): void
    {
        $this->assertSame(['PB-KEU'], $this->terlihat($this->mudirKeuangan));
        $this->assertSame(['PB-GS'], $this->terlihat($this->mudirOperasional));
    }

    public function test_staff_tetap_sebatas_bagiannya(): void
    {
        $this->assertSame(['PB-KEU'], $this->terlihat($this->staffKeu));
    }

    /** Tanpa bagian, ia hanya berhak atas dokumen yang ia ajukan sendiri. */
    public function test_tanpa_bagian_hanya_miliknya_sendiri(): void
    {
        $lepas = $this->pengguna('lepas', 'Tanpa Bagian', null, null);
        $this->assertSame([], $this->terlihat($lepas));

        $this->buatPengajuan('PB-SENDIRI', 'DIV-GS', $lepas);
        $this->assertSame(['PB-SENDIRI'], $this->terlihat($lepas));
    }

    public function test_admin_dan_tim_keuangan_tanpa_batas(): void
    {
        $admin = User::create(['username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif']);
        $keuangan = User::create(['username' => 'keu', 'nama' => 'Keuangan', 'password_hash' => 'x',
            'kode_level' => 'L1', 'tim_keuangan' => true, 'status' => 'aktif']);

        $this->assertSame(['PB-GS', 'PB-KEU'], $this->terlihat($admin));
        $this->assertSame(['PB-GS', 'PB-KEU'], $this->terlihat($keuangan));
    }

    public function test_daftar_di_layar_ikut_menampilkannya(): void
    {
        $this->actingAs($this->ketuaYayasan)->get(route('pengajuan.index'))
            ->assertOk()
            ->assertSee('PB-KEU')
            ->assertSee('PB-GS');
    }

    /** Ringkasan dashboard wajib memakai aturan yang sama dengan daftarnya. */
    public function test_dashboard_memakai_aturan_yang_sama(): void
    {
        $svc = new StatusAnggaranPengajuanService;

        $this->assertSame(2, $svc->pengajuan($this->ketuaYayasan)['matriks']['pembayaran']['lunas']['jumlah']);
        $this->assertSame(1, $svc->pengajuan($this->mudirKeuangan)['matriks']['pembayaran']['lunas']['jumlah']);
    }

    /** Induk melingkar (salah isi master) tak boleh menggantung daftarnya. */
    public function test_induk_melingkar_tidak_membuat_gantung(): void
    {
        Bagian::whereKey('YYS')->update(['kode_induk' => 'DIV-KEU']);

        $this->assertNotEmpty($this->terlihat($this->ketuaYayasan));
    }
}
