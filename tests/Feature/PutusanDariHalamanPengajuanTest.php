<?php

namespace Tests\Feature;

use App\Models\ApprovalFlow;
use App\Models\ApprovalInstance;
use App\Models\Bagian;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\HakAksesModul;
use App\Models\Level;
use App\Models\LevelPengajuan;
use App\Models\PengajuanPembayaran;
use App\Models\User;
use App\Services\Modules\ApprovalService;
use App\Services\Modules\PengajuanPembayaranService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SETUJUI / TOLAK LANGSUNG DARI HALAMAN PENGAJUAN.
 *
 * Approver membaca rincian di halaman dokumen, lalu harus kembali ke
 * "Persetujuan Saya" hanya untuk menekan tombol — di situlah keputusan diambil
 * tanpa melihat rinciannya lagi.
 *
 * Yang dijaga: tombolnya hanya muncul untuk penyetuju tahap yang SEDANG
 * berjalan, dan pengalihan sesudah keputusan tak boleh bisa dikendalikan isian
 * form (celah pengalihan terbuka).
 */
class PutusanDariHalamanPengajuanTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZPUT';

    private const BEBAN = '5.ZZPUT.1';

    private const UNIT = 'ZZPUTU';

    private PengajuanPembayaranService $svc;

    private User $staff;

    private User $mudirBagian;

    private User $mudirLain;

    protected function setUp(): void
    {
        parent::setUp();
        ApprovalService::resetRegistry();
        ApprovalService::daftarPenolakan(PengajuanPembayaranService::SUMBER, fn ($id) => (new PengajuanPembayaranService)->applyRejected($id));
        $this->svc = new PengajuanPembayaranService;

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        LevelPengajuan::create(['peringkat' => 3, 'nama' => 'Mudir Bagian']);
        LevelPengajuan::create(['peringkat' => 4, 'nama' => 'Staff']);
        Bagian::create(['kode_bagian' => 'B1', 'nama_bagian' => 'Bagian 1', 'level' => 3]);
        Bagian::create(['kode_bagian' => 'B2', 'nama_bagian' => 'Bagian 2', 'level' => 3]);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Putusan']);
        CoaDetail::create(['kode_coa' => self::BEBAN, 'nama_coa' => 'Beban ATK', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);

        $this->staff = $this->pengguna('staff', 'Staff', 'B1', 4);
        $this->mudirBagian = $this->pengguna('mudir1', 'Mudir Satu', 'B1', 3);
        $this->mudirLain = $this->pengguna('mudir2', 'Mudir Dua', 'B2', 3);

        $flow = ApprovalFlow::create(['kode_flow' => 'FPUT', 'nama_flow' => 'Pengajuan', 'jenis_dokumen' => PengajuanPembayaranService::SUMBER]);
        $flow->steps()->create(['urutan' => 1, 'nama_tahap' => 'Mudir Bagian', 'peringkat' => 3, 'scope' => 'bagian']);
    }

    private function pengguna(string $username, string $nama, string $bagian, int $peringkat): User
    {
        $u = User::create(['username' => $username, 'nama' => $nama, 'password_hash' => 'x',
            'kode_level' => 'L1', 'kode_bagian' => $bagian, 'peringkat_pengajuan' => $peringkat, 'status' => 'aktif']);
        HakAksesModul::create(['id_pengguna' => $u->id_pengguna, 'kode_modul' => 'pengajuan-pembayaran',
            'lihat' => true, 'buat' => true, 'ubah' => false, 'hapus' => false, 'menu' => true]);

        return $u;
    }

    private function buatPengajuan(): PengajuanPembayaran
    {
        return $this->svc->create([
            'tanggal' => '2026-07-15', 'jenis' => 'pembayaran', 'keterangan' => 'Beli ATK',
            'details' => [['kode_coa' => self::BEBAN, 'kode_unit' => self::UNIT, 'nominal' => '100000']],
        ], $this->staff->id_pengguna);
    }

    private function rantai(PengajuanPembayaran $p): ApprovalInstance
    {
        return ApprovalInstance::where('jenis_dokumen', PengajuanPembayaranService::SUMBER)
            ->where('id_dokumen', (string) $p->id)->firstOrFail();
    }

    public function test_penyetuju_melihat_tombol_di_halaman_dokumen(): void
    {
        $p = $this->buatPengajuan();

        $this->actingAs($this->mudirBagian)->get(route('pengajuan.show', $p->id))
            ->assertOk()
            ->assertSee('Keputusan Anda')
            ->assertSee('Setujui')
            ->assertSee('Alasan penolakan')
            // Nominal di dalam data-confirm harus teks polos. Dengan @rp,
            // kutip pada <span class="rp"> menutup atributnya lebih awal dan
            // sisa markupnya tercetak sebagai kalimat di layar.
            ->assertSee('sebesar Rp 100.000?"', false);
    }

    /** Pemohon & penyetuju bagian lain tak boleh melihat tombolnya. */
    public function test_yang_bukan_penyetuju_tidak_melihat_tombol(): void
    {
        $p = $this->buatPengajuan();

        foreach ([$this->staff, $this->mudirLain] as $orang) {
            $this->actingAs($orang)->get(route('pengajuan.show', $p->id))
                ->assertOk()
                ->assertDontSee('Keputusan Anda');
        }
    }

    public function test_menyetujui_dari_halaman_kembali_ke_halaman_itu(): void
    {
        $p = $this->buatPengajuan();
        $inst = $this->rantai($p);

        $this->actingAs($this->mudirBagian)
            ->post(route('approvals.approve', $inst->id), ['kembali' => 'dokumen', 'catatan' => 'setuju, lanjut'])
            ->assertRedirect(route('pengajuan.show', $p->id))
            ->assertSessionHas('status');

        $this->assertSame('disetujui', $inst->refresh()->status);
        $this->assertDatabaseHas('approval_logs', ['id_instance' => $inst->id, 'aksi' => 'approve', 'catatan' => 'setuju, lanjut']);
    }

    public function test_menolak_dari_halaman_kembali_ke_halaman_itu(): void
    {
        $p = $this->buatPengajuan();
        $inst = $this->rantai($p);

        $this->actingAs($this->mudirBagian)
            ->post(route('approvals.reject', $inst->id), ['kembali' => 'dokumen', 'alasan' => 'nota belum lengkap'])
            ->assertRedirect(route('pengajuan.show', $p->id));

        $this->assertSame('ditolak', $inst->refresh()->status);
        $this->assertSame('ditolak', $p->refresh()->status);
    }

    public function test_menolak_tanpa_alasan_ditolak(): void
    {
        $p = $this->buatPengajuan();
        $inst = $this->rantai($p);

        $this->actingAs($this->mudirBagian)
            ->post(route('approvals.reject', $inst->id), ['kembali' => 'dokumen'])
            ->assertSessionHasErrors('alasan');

        $this->assertSame('berjalan', $inst->refresh()->status);
    }

    /** Tanpa penanda "kembali", perilaku lama dipertahankan: balik ke kotak masuk. */
    public function test_dari_kotak_masuk_tetap_kembali_ke_kotak_masuk(): void
    {
        $p = $this->buatPengajuan();
        $inst = $this->rantai($p);

        $this->actingAs($this->mudirBagian)->post(route('approvals.approve', $inst->id))
            ->assertRedirect(route('approvals.inbox'));
    }

    /**
     * Tujuan pengalihan dibangun dari instance-nya, bukan dari isian form —
     * kalau tidak, form yang dipalsukan bisa melempar approver ke mana saja.
     */
    public function test_alamat_kembali_tidak_bisa_disetir_isian_form(): void
    {
        $p = $this->buatPengajuan();
        $inst = $this->rantai($p);

        $this->actingAs($this->mudirBagian)->post(route('approvals.approve', $inst->id), [
            'kembali' => 'https://situs-lain.example/curi',
        ])->assertRedirect(route('approvals.inbox'));
    }

    /** Tahap yang sudah lewat tak boleh menyisakan tombol yang masih bisa ditekan. */
    public function test_tombol_hilang_setelah_rantai_selesai(): void
    {
        $p = $this->buatPengajuan();
        $inst = $this->rantai($p);
        (new ApprovalService)->approve($inst->id, $this->mudirBagian->id_pengguna);

        $this->actingAs($this->mudirBagian)->get(route('pengajuan.show', $p->id))
            ->assertOk()
            ->assertDontSee('Keputusan Anda');
    }
}
