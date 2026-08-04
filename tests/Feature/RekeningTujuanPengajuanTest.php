<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\ApprovalFlow;
use App\Models\Bagian;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\HakAksesModul;
use App\Models\Level;
use App\Models\LevelPengajuan;
use App\Models\PengajuanPembayaran;
use App\Models\RekeningTersimpan;
use App\Models\User;
use App\Services\Modules\ApprovalService;
use App\Services\Modules\PengajuanPembayaranService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * REKENING TUJUAN PEMBAYARAN pada pengajuan + buku rekening pemohon.
 *
 * Tiga hal yang dijaga ketat di sini, karena tiga-tiganya berujung pada uang
 * yang dikirim ke rekening yang salah:
 *
 *  1. Rekening setengah terisi tak boleh pernah tersimpan — nomor tanpa nama
 *     bank tampak sudah lengkap padahal tak bisa dipakai.
 *  2. Buku rekening seorang pemohon tak boleh bocor ke pemohon lain.
 *  3. Setiap penyuntingan oleh keuangan WAJIB berjejak & memberi tahu pemohon.
 *     Penggantian rekening senyap sesudah dokumen disetujui adalah modus
 *     penipuan pembayaran yang paling umum.
 */
class RekeningTujuanPengajuanTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZRT';

    private const BEBAN = '5.ZZRT.1';

    private const HUTANG = '2.ZZRT.1';

    private const UNIT = 'ZZRTU';

    private PengajuanPembayaranService $svc;

    private User $staff;

    private User $staffLain;

    private User $keuangan;

    protected function setUp(): void
    {
        parent::setUp();
        ApprovalService::resetRegistry();
        $this->svc = new PengajuanPembayaranService;

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        LevelPengajuan::create(['peringkat' => 3, 'nama' => 'Mudir Bagian']);
        LevelPengajuan::create(['peringkat' => 4, 'nama' => 'Staff']);
        Bagian::create(['kode_bagian' => 'B1', 'nama_bagian' => 'Bagian 1', 'level' => 3]);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Rek Test']);
        CoaDetail::create(['kode_coa' => self::BEBAN, 'nama_coa' => 'Beban ATK', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => self::HUTANG, 'nama_coa' => 'Hutang Pengajuan', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);

        $this->staff = User::create(['username' => 'staff', 'nama' => 'Staff', 'password_hash' => 'x',
            'kode_level' => 'L1', 'kode_bagian' => 'B1', 'peringkat_pengajuan' => 4, 'status' => 'aktif']);
        $this->staffLain = User::create(['username' => 'staff2', 'nama' => 'Staff Dua', 'password_hash' => 'x',
            'kode_level' => 'L1', 'kode_bagian' => 'B1', 'peringkat_pengajuan' => 4, 'status' => 'aktif']);
        $this->keuangan = User::create(['username' => 'keu', 'nama' => 'Keuangan', 'password_hash' => 'x',
            'kode_level' => 'L1', 'tim_keuangan' => true, 'status' => 'aktif']);

        foreach ([$this->staff, $this->staffLain, $this->keuangan] as $u) {
            HakAksesModul::create(['id_pengguna' => $u->id_pengguna, 'kode_modul' => 'pengajuan-pembayaran',
                'lihat' => true, 'buat' => true, 'ubah' => true, 'hapus' => false, 'menu' => true]);
        }

        $flow = ApprovalFlow::create(['kode_flow' => 'FPP', 'nama_flow' => 'Pengajuan', 'jenis_dokumen' => PengajuanPembayaranService::SUMBER]);
        $flow->steps()->create(['urutan' => 1, 'nama_tahap' => 'Mudir Bagian', 'peringkat' => 3, 'scope' => 'bagian']);
    }

    /** @param array<string,string> $tambahan */
    private function kirimPengajuan(array $tambahan = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->staff)->post(route('pengajuan.store'), [
            'jenis' => 'pembayaran',
            'tanggal' => '2026-07-15',
            'keterangan' => 'Beli ATK',
            'details' => [['kode_coa' => self::BEBAN, 'kode_unit' => self::UNIT, 'nominal' => '100000']],
            ...$tambahan,
        ]);
    }

    private function buatLangsung(array $rekening = []): PengajuanPembayaran
    {
        return $this->svc->create([
            'tanggal' => '2026-07-15', 'jenis' => 'pembayaran', 'keterangan' => 'Beli ATK',
            'details' => [['kode_coa' => self::BEBAN, 'kode_unit' => self::UNIT, 'nominal' => '100000']],
            ...$rekening,
        ], $this->staff->id_pengguna);
    }

    // ---- Pengisian ----

    public function test_rekening_tujuan_boleh_dikosongkan(): void
    {
        $this->kirimPengajuan()->assertSessionHasNoErrors();

        $p = PengajuanPembayaran::firstOrFail();
        $this->assertNull($p->bank_tujuan);
        $this->assertFalse($p->punyaRekeningTujuan());
    }

    public function test_rekening_lengkap_tersimpan_di_pengajuan(): void
    {
        $this->kirimPengajuan([
            'bank_tujuan' => 'BSI', 'no_rekening_tujuan' => '7001234567', 'atas_nama_tujuan' => 'CV Sumber Rejeki',
        ])->assertSessionHasNoErrors();

        $p = PengajuanPembayaran::firstOrFail();
        $this->assertSame('BSI', $p->bank_tujuan);
        $this->assertSame('7001234567', $p->no_rekening_tujuan);
        $this->assertSame('CV Sumber Rejeki', $p->atas_nama_tujuan);
        $this->assertTrue($p->punyaRekeningTujuan());
    }

    /**
     * Setengah terisi ditolak: isian yang tampak sudah diisi tapi tak bisa
     * dipakai mentransfer lebih berbahaya daripada isian yang jelas kosong.
     */
    public function test_rekening_setengah_terisi_ditolak(): void
    {
        $this->kirimPengajuan(['no_rekening_tujuan' => '7001234567'])
            ->assertSessionHasErrors(['bank_tujuan', 'atas_nama_tujuan']);

        $this->kirimPengajuan(['bank_tujuan' => 'BSI', 'atas_nama_tujuan' => 'CV Sumber Rejeki'])
            ->assertSessionHasErrors('no_rekening_tujuan');

        $this->assertSame(0, PengajuanPembayaran::count());
    }

    public function test_nomor_rekening_harus_angka(): void
    {
        $this->kirimPengajuan([
            'bank_tujuan' => 'BSI', 'no_rekening_tujuan' => 'rekening bank saya', 'atas_nama_tujuan' => 'CV Sumber Rejeki',
        ])->assertSessionHasErrors('no_rekening_tujuan');
    }

    // ---- Buku rekening pemohon ----

    public function test_rekening_disimpan_ke_buku_hanya_bila_dicentang(): void
    {
        $this->kirimPengajuan([
            'bank_tujuan' => 'BSI', 'no_rekening_tujuan' => '7001234567', 'atas_nama_tujuan' => 'CV Sumber Rejeki',
        ]);
        $this->assertSame(0, RekeningTersimpan::count());

        $this->kirimPengajuan([
            'bank_tujuan' => 'BSI', 'no_rekening_tujuan' => '7001234567', 'atas_nama_tujuan' => 'CV Sumber Rejeki',
            'simpan_rekening' => '1',
        ]);

        $this->assertSame(1, RekeningTersimpan::count());
        $this->assertSame($this->staff->id_pengguna, RekeningTersimpan::first()->id_pengguna);
    }

    /** Menyimpan rekening yang sama dua kali tak boleh memanjangkan daftarnya. */
    public function test_menyimpan_rekening_sama_tidak_menggandakan(): void
    {
        foreach (['CV Sumber Rejeki', 'CV Sumber Rejeki Abadi'] as $atasNama) {
            $this->kirimPengajuan([
                'bank_tujuan' => 'BSI', 'no_rekening_tujuan' => '7001234567', 'atas_nama_tujuan' => $atasNama,
                'simpan_rekening' => '1',
            ]);
        }

        $this->assertSame(1, RekeningTersimpan::count());
        // Ejaan atas nama yang diperbaiki ikut terbarui.
        $this->assertSame('CV Sumber Rejeki Abadi', RekeningTersimpan::first()->atas_nama);
    }

    public function test_buku_rekening_tidak_bocor_ke_pemohon_lain(): void
    {
        $this->kirimPengajuan([
            'bank_tujuan' => 'BSI', 'no_rekening_tujuan' => '7001234567', 'atas_nama_tujuan' => 'CV Sumber Rejeki',
            'simpan_rekening' => '1',
        ]);

        $this->actingAs($this->staff)->get(route('pengajuan.create'))
            ->assertOk()->assertSee('7001234567');

        $this->actingAs($this->staffLain)->get(route('pengajuan.create'))
            ->assertOk()->assertDontSee('7001234567');

        $this->assertSame([], RekeningTersimpan::untukPemohon($this->staffLain->id_pengguna));
    }

    // ---- Penyuntingan oleh keuangan ----

    public function test_keuangan_mengubah_rekening_meninggalkan_jejak(): void
    {
        $p = $this->buatLangsung([
            'bank_tujuan' => 'BSI', 'no_rekening_tujuan' => '7001234567', 'atas_nama_tujuan' => 'CV Sumber Rejeki',
        ]);

        $this->svc->ubahRekeningTujuan($p->id, [
            'bank_tujuan' => 'BSI', 'no_rekening_tujuan' => '7009999999', 'atas_nama_tujuan' => 'CV Sumber Rejeki',
        ], $this->keuangan->id_pengguna, 'Nomor lama sudah ditutup, konfirmasi vendor 3 Agu.');

        $p->refresh();
        $this->assertSame('7009999999', $p->no_rekening_tujuan);

        $jejak = $p->riwayatRekening()->first();
        $this->assertNotNull($jejak);
        $this->assertSame('7001234567', $jejak->no_rekening_lama);
        $this->assertSame('7009999999', $jejak->no_rekening_baru);
        $this->assertSame($this->keuangan->id_pengguna, $jejak->id_pengguna);
        $this->assertStringContainsString('ditutup', $jejak->alasan);

        // Pemohon — bukan hanya keuangan — harus tahu rekeningnya diganti.
        $this->assertDatabaseHas('notifications', [
            'id_pengguna' => $this->staff->id_pengguna,
            'judul' => 'Rekening tujuan pengajuan diubah keuangan',
        ]);
    }

    public function test_perubahan_rekening_wajib_beralasan(): void
    {
        $p = $this->buatLangsung([
            'bank_tujuan' => 'BSI', 'no_rekening_tujuan' => '7001234567', 'atas_nama_tujuan' => 'CV Sumber Rejeki',
        ]);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/[Aa]lasan/');
        $this->svc->ubahRekeningTujuan($p->id, [
            'bank_tujuan' => 'BSI', 'no_rekening_tujuan' => '7009999999', 'atas_nama_tujuan' => 'CV Sumber Rejeki',
        ], $this->keuangan->id_pengguna, '   ');
    }

    public function test_selain_tim_keuangan_tidak_boleh_mengubah_rekening(): void
    {
        $p = $this->buatLangsung([
            'bank_tujuan' => 'BSI', 'no_rekening_tujuan' => '7001234567', 'atas_nama_tujuan' => 'CV Sumber Rejeki',
        ]);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/tim keuangan/i');
        $this->svc->ubahRekeningTujuan($p->id, [
            'bank_tujuan' => 'BSI', 'no_rekening_tujuan' => '7009999999', 'atas_nama_tujuan' => 'CV Sumber Rejeki',
        ], $this->staff->id_pengguna, 'iseng');
    }

    /** Service tak boleh menggantungkan kelengkapan pada satu layar saja. */
    public function test_service_menolak_rekening_setengah_terisi(): void
    {
        $p = $this->buatLangsung([
            'bank_tujuan' => 'BSI', 'no_rekening_tujuan' => '7001234567', 'atas_nama_tujuan' => 'CV Sumber Rejeki',
        ]);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/lengkap/i');
        $this->svc->ubahRekeningTujuan($p->id, [
            'bank_tujuan' => 'BCA', 'no_rekening_tujuan' => null, 'atas_nama_tujuan' => null,
        ], $this->keuangan->id_pengguna, 'pindah bank');
    }

    /** Kalau rekening dokumen yang sudah diposting keliru, jalurnya membatalkan — bukan menyunting. */
    public function test_rekening_terkunci_setelah_pengajuan_tidak_lagi_berjalan(): void
    {
        $p = $this->buatLangsung([
            'bank_tujuan' => 'BSI', 'no_rekening_tujuan' => '7001234567', 'atas_nama_tujuan' => 'CV Sumber Rejeki',
        ]);
        $p->update(['status' => 'diposting']);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/belum diposting/i');
        $this->svc->ubahRekeningTujuan($p->id, [
            'bank_tujuan' => 'BSI', 'no_rekening_tujuan' => '7009999999', 'atas_nama_tujuan' => 'CV Sumber Rejeki',
        ], $this->keuangan->id_pengguna, 'terlanjur');
    }

    /**
     * Layar verifikasi menolak perubahan tanpa alasan SEBELUM apa pun tersimpan
     * — kalau tidak, keuangan bisa mengganti rekening hanya dengan mengetik di
     * isian yang sudah terisi lalu menekan Verifikasi.
     */
    public function test_layar_verifikasi_menolak_perubahan_rekening_tanpa_alasan(): void
    {
        $p = $this->buatLangsung([
            'bank_tujuan' => 'BSI', 'no_rekening_tujuan' => '7001234567', 'atas_nama_tujuan' => 'CV Sumber Rejeki',
        ]);

        $this->actingAs($this->keuangan)->post(route('pengajuan.verifikasi', $p->id), [
            'kode_coa_hutang' => self::HUTANG,
            'bank_tujuan' => 'BSI', 'no_rekening_tujuan' => '7009999999', 'atas_nama_tujuan' => 'CV Sumber Rejeki',
        ])->assertSessionHas('error');

        $this->assertSame('7001234567', $p->fresh()->no_rekening_tujuan);
        $this->assertSame(0, $p->riwayatRekening()->count());
    }

    public function test_rekening_tampil_di_halaman_pengajuan(): void
    {
        $p = $this->buatLangsung([
            'bank_tujuan' => 'BSI', 'no_rekening_tujuan' => '7001234567', 'atas_nama_tujuan' => 'CV Sumber Rejeki',
        ]);

        $this->actingAs($this->staff)->get(route('pengajuan.show', $p->id))
            ->assertOk()
            ->assertSee('Rekening Tujuan Pembayaran')
            ->assertSee('7001234567');
    }
}
