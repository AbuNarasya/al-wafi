<?php

namespace Tests\Feature;

use App\Models\ApprovalFlow;
use App\Models\ApprovalInstance;
use App\Models\Bagian;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\HakAksesModul;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Level;
use App\Models\LevelPengajuan;
use App\Models\PengajuanPembayaran;
use App\Models\User;
use App\Services\Modules\ApprovalService;
use App\Services\Modules\PengajuanPembayaranService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SIAPA yang boleh membatalkan pengajuan, dan pada tahap mana.
 *
 * Aturannya bergeser mengikuti akibatnya, bukan mengikuti jabatan:
 *
 *  - Selama BELUM BERJURNAL (`diajukan`/`ditolak`), pembatalan hanya
 *    menghentikan selembar surat. Pemohonnya sendiri yang berhak.
 *  - Sesudah keuangan memverifikasi (`diverifikasi`/`diposting`), hutangnya
 *    sudah masuk buku besar dan pembatalan MEMBALIK JURNAL. Yang berhak tim
 *    keuangan — pemohon tak melihat akibat akuntansinya.
 *
 * Tim keuangan mana pun boleh, TAK HARUS orang yang memverifikasi: menahan
 * pembatalan di satu orang membuat dokumen keliru menggantung saat ia
 * berhalangan, dan yang menanggung justru vendor yang menunggu.
 */
class VoidPengajuanTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZVD';

    private const BEBAN = '5.ZZVD.1';

    private const HUTANG = '2.ZZVD.1';

    private const UNIT = 'ZZVDU';

    private PengajuanPembayaranService $svc;

    private User $staff;

    private User $mudir;

    private User $keuangan;

    private User $keuanganLain;

    protected function setUp(): void
    {
        parent::setUp();
        ApprovalService::resetRegistry();

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        LevelPengajuan::create(['peringkat' => 3, 'nama' => 'Mudir Bagian']);
        LevelPengajuan::create(['peringkat' => 4, 'nama' => 'Staff']);
        Bagian::create(['kode_bagian' => 'B1', 'nama_bagian' => 'Bagian 1', 'level' => 3]);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Void Test']);
        CoaDetail::create(['kode_coa' => self::BEBAN, 'nama_coa' => 'Beban ATK', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => self::HUTANG, 'nama_coa' => 'Hutang Pengajuan', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);

        $this->svc = new PengajuanPembayaranService;

        $this->staff = User::create(['username' => 'staff', 'nama' => 'Staff', 'password_hash' => 'x',
            'kode_level' => 'L1', 'kode_bagian' => 'B1', 'peringkat_pengajuan' => 4, 'status' => 'aktif']);
        $this->mudir = User::create(['username' => 'mudir', 'nama' => 'Mudir', 'password_hash' => 'x',
            'kode_level' => 'L1', 'kode_bagian' => 'B1', 'peringkat_pengajuan' => 3, 'status' => 'aktif']);
        $this->keuangan = User::create(['username' => 'keu', 'nama' => 'Keuangan', 'password_hash' => 'x',
            'kode_level' => 'L1', 'tim_keuangan' => true, 'status' => 'aktif']);
        // Orang keuangan KEDUA — yang tak pernah menyentuh dokumen ini.
        $this->keuanganLain = User::create(['username' => 'keu2', 'nama' => 'Keuangan Dua', 'password_hash' => 'x',
            'kode_level' => 'L1', 'tim_keuangan' => true, 'status' => 'aktif']);

        foreach ([$this->staff, $this->mudir, $this->keuangan, $this->keuanganLain] as $u) {
            HakAksesModul::create(['id_pengguna' => $u->id_pengguna, 'kode_modul' => 'pengajuan-pembayaran',
                'lihat' => true, 'buat' => true, 'ubah' => true, 'hapus' => true, 'menu' => true]);
        }

        $flow = ApprovalFlow::create(['kode_flow' => 'FPP', 'nama_flow' => 'Pengajuan', 'jenis_dokumen' => PengajuanPembayaranService::SUMBER]);
        $flow->steps()->create(['urutan' => 1, 'nama_tahap' => 'Mudir Bagian', 'peringkat' => 3, 'scope' => 'bagian']);
    }

    private function buat(): PengajuanPembayaran
    {
        return $this->svc->create([
            'tanggal' => '2026-07-15', 'jenis' => 'pembayaran', 'keterangan' => 'Beli ATK',
            'details' => [['kode_coa' => self::BEBAN, 'kode_unit' => self::UNIT, 'nominal' => '100000']],
        ], $this->staff->id_pengguna);
    }

    /** Sampai berstatus `diposting`: disetujui mudir, lalu diverifikasi keuangan. */
    private function sampaiDiposting(): PengajuanPembayaran
    {
        $p = $this->buat();
        $inst = ApprovalInstance::where('jenis_dokumen', PengajuanPembayaranService::SUMBER)
            ->where('id_dokumen', (string) $p->id)->firstOrFail();
        (new ApprovalService)->approve($inst->id, $this->mudir->id_pengguna);
        $this->svc->verifikasi($p->id, self::HUTANG, $this->keuangan->id_pengguna);

        return $p->refresh();
    }

    public function test_pemohon_boleh_membatalkan_pengajuannya_yang_belum_berjurnal(): void
    {
        $p = $this->buat();

        $this->actingAs($this->staff)
            ->delete(route('pengajuan.void', $p->id), ['alasan' => 'Salah nominal'])
            ->assertRedirect();

        $this->assertSame('void', $p->refresh()->status);
    }

    /** Inti permintaannya: verifikasi bukan titik yang tak bisa diputar balik. */
    public function test_tim_keuangan_boleh_membatalkan_pengajuan_yang_sudah_diverifikasi(): void
    {
        $p = $this->sampaiDiposting();
        $this->assertSame('diposting', $p->status);

        $this->actingAs($this->keuangan)
            ->delete(route('pengajuan.void', $p->id), ['alasan' => 'Vendor membatalkan pesanan'])
            ->assertRedirect();

        $p->refresh();
        $this->assertSame('void', $p->status);
        $this->assertSame('Vendor membatalkan pesanan', $p->void_reason);
        $this->assertSame(0.0, (float) $p->sisa_hutang);
    }

    /** Bukan hanya si pemverifikasi — tim keuangan mana pun. */
    public function test_orang_keuangan_lain_juga_boleh_membatalkannya(): void
    {
        $p = $this->sampaiDiposting();

        $this->actingAs($this->keuanganLain)
            ->delete(route('pengajuan.void', $p->id), ['alasan' => 'Dobel dengan PP lain'])
            ->assertRedirect();

        $this->assertSame('void', $p->refresh()->status);
    }

    /**
     * Jurnal hutangnya WAJIB terbalik. Tanpa ini pembatalan hanya mengubah
     * label di layar, sementara buku besar tetap mencatat kewajiban yang sudah
     * tak ada — dan neraca menyimpan hutang hantu.
     *
     * Yang diperiksa ANGKANYA, bukan jumlah barisnya: pembalikan di sini tidak
     * menghapus apa pun. Jurnal asli ditandai `void` dan jurnal pembalik baru
     * ditulis di sebelahnya, sehingga jejaknya utuh dan yang saling meniadakan
     * adalah debet lawan kreditnya.
     */
    public function test_pembatalan_sesudah_verifikasi_membalik_jurnalnya(): void
    {
        $p = $this->sampaiDiposting();

        $asli = JournalEntry::where('sumber_modul', PengajuanPembayaranService::SUMBER)
            ->where('id_sumber', (string) $p->id)->sole();
        $this->assertSame('aktif', $asli->status);
        $this->assertSame(100000.0, (float) $this->saldoHutang($p));

        $this->actingAs($this->keuangan)
            ->delete(route('pengajuan.void', $p->id), ['alasan' => 'Vendor membatalkan pesanan'])
            ->assertRedirect();

        $this->assertSame('void', $asli->refresh()->status);

        $pembalik = JournalEntry::where('reversal_of', $asli->id)->sole();
        $this->assertSame((string) $p->id, $pembalik->id_sumber);

        // Inilah yang sebenarnya penting: hutangnya kembali NOL.
        $this->assertSame(0.0, (float) $this->saldoHutang($p));
    }

    /** Saldo akun hutang (kredit − debet) dari seluruh jurnal dokumen ini. */
    private function saldoHutang(PengajuanPembayaran $p): string
    {
        $ids = JournalEntry::where('sumber_modul', PengajuanPembayaranService::SUMBER)
            ->where('id_sumber', (string) $p->id)->pluck('id');

        $l = JournalLine::whereIn('entry_id', $ids)->where('kode_coa', self::HUTANG)
            ->selectRaw('COALESCE(SUM(kredit),0) - COALESCE(SUM(debet),0) AS saldo')->value('saldo');

        return (string) $l;
    }

    /**
     * Pemohon TIDAK boleh membatalkan sesudah dokumennya berjurnal, walaupun ia
     * yang mengajukan. Yang dibatalkannya bukan lagi suratnya sendiri.
     */
    public function test_pemohon_tak_boleh_membatalkan_sesudah_diverifikasi(): void
    {
        $p = $this->sampaiDiposting();

        $this->actingAs($this->staff)
            ->delete(route('pengajuan.void', $p->id), ['alasan' => 'Berubah pikiran'])
            ->assertForbidden();

        $this->assertSame('diposting', $p->refresh()->status);
    }

    /** Pengajuan orang lain yang belum berjurnal pun bukan urusan tim keuangan. */
    public function test_tim_keuangan_tak_boleh_membatalkan_yang_belum_diverifikasi(): void
    {
        $p = $this->buat();

        $this->actingAs($this->keuangan)
            ->delete(route('pengajuan.void', $p->id), ['alasan' => 'Sepertinya keliru'])
            ->assertForbidden();

        $this->assertSame('diajukan', $p->refresh()->status);
    }
}
