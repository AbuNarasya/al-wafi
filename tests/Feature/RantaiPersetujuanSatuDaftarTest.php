<?php

namespace Tests\Feature;

use App\Models\ApprovalFlow;
use App\Models\ApprovalInstance;
use App\Models\Bagian;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\Level;
use App\Models\LevelPengajuan;
use App\Models\User;
use App\Services\Modules\ApprovalService;
use App\Services\Modules\PengajuanPembayaranService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rantai persetujuan tampil sebagai SATU daftar: tiap tahap menyebut statusnya
 * berikut NAMA orangnya, dalam kurung.
 *
 * Dulu terbelah dua — "Tahap Persetujuan" tanpa nama, dan "Riwayat" tanpa tahap
 * — sehingga untuk tahu siapa yang menyetujui tahap tertentu pembaca harus
 * memasangkan sendiri kedua daftar itu. Pemasangan itu kini dikerjakan layar,
 * lewat `approval_logs.urutan`, dan justru itulah yang paling perlu dijaga:
 * kalau pemasangannya meleset, nama penyetuju akan menempel pada tahap yang
 * SALAH — keliru yang terlihat meyakinkan.
 */
class RantaiPersetujuanSatuDaftarTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZRP';
    private const BEBAN = '5.ZZRP.1';
    private const UNIT = 'ZZRPU';

    private PengajuanPembayaranService $svc;
    private ApprovalService $appr;
    private int $staf;
    private int $mudirBagian;
    private int $mudirUmum;
    private User $keuangan;

    protected function setUp(): void
    {
        parent::setUp();
        ApprovalService::resetRegistry();
        $this->svc = new PengajuanPembayaranService;
        $this->appr = new ApprovalService;

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        LevelPengajuan::create(['peringkat' => 3, 'nama' => 'Mudir Bagian']);
        LevelPengajuan::create(['peringkat' => 2, 'nama' => 'Mudir Direktorat']);
        LevelPengajuan::create(['peringkat' => 4, 'nama' => 'Staff']);
        Bagian::create(['kode_bagian' => 'B1', 'nama_bagian' => 'Divisi Keuangan', 'level' => 3]);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Yayasan']);
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Uji']);
        CoaDetail::create(['kode_coa' => self::BEBAN, 'nama_coa' => 'Beban Transportasi', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);

        $this->staf = User::create(['username' => 'zzrp_staf', 'nama' => 'Fanji Ahmad Maulana', 'password_hash' => 'x', 'kode_level' => 'L1', 'kode_bagian' => 'B1', 'peringkat_pengajuan' => 4, 'is_admin' => true])->id_pengguna;
        $this->mudirBagian = User::create(['username' => 'zzrp_mb', 'nama' => 'Juainy Anwar', 'password_hash' => 'x', 'kode_level' => 'L1', 'kode_bagian' => 'B1', 'peringkat_pengajuan' => 3])->id_pengguna;
        $this->mudirUmum = User::create(['username' => 'zzrp_mu', 'nama' => 'Kiki Kusumayadi', 'password_hash' => 'x', 'kode_level' => 'L1', 'kode_bagian' => 'B1', 'peringkat_pengajuan' => 2])->id_pengguna;
        $this->keuangan = User::create(['username' => 'zzrp_keu', 'nama' => 'Marullah Marzuq', 'password_hash' => 'x', 'kode_level' => 'L1', 'tim_keuangan' => true]);

        $flow = ApprovalFlow::create(['kode_flow' => 'FRP', 'nama_flow' => 'Pengajuan', 'jenis_dokumen' => PengajuanPembayaranService::SUMBER]);
        $flow->steps()->create(['urutan' => 1, 'nama_tahap' => 'Mudir Bagian', 'peringkat' => 3, 'scope' => 'bagian']);
        $flow->steps()->create(['urutan' => 2, 'nama_tahap' => 'Mudir Umum', 'peringkat' => 2, 'scope' => 'semua']);
    }

    private function buat(): int
    {
        return $this->svc->create([
            'tanggal' => '2026-08-04', 'jenis' => 'pembayaran', 'keterangan' => 'BBM dan Etoll',
            'details' => [['kode_coa' => self::BEBAN, 'kode_unit' => self::UNIT, 'nominal' => '1000000']],
        ], $this->staf)->id;
    }

    /** Id instance rantai untuk sebuah dokumen. */
    private function idRantai(int $id): int
    {
        return ApprovalInstance::where("jenis_dokumen", PengajuanPembayaranService::SUMBER)
            ->where("id_dokumen", (string) $id)->firstOrFail()->id;
    }

    private function halaman(int $id): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs(User::find($this->staf))->get(route('pengajuan.show', $id))->assertOk();
    }

    public function test_tahap_yang_menunggu_menyebut_di_siapa(): void
    {
        $h = $this->halaman($this->buat());

        $h->assertSee('Mudir Bagian')->assertSee('menunggu di')->assertSee('Juainy Anwar');
        // Tahap berikutnya terlihat, tetapi belum bergiliran.
        $h->assertSee('belum giliran');
        // Dua daftar terpisah yang lama tak boleh kembali.
        $h->assertDontSee('Tahap Persetujuan')->assertDontSee('>Riwayat<', false);
    }

    public function test_penyetuju_menempel_pada_tahapnya_sendiri(): void
    {
        $id = $this->buat();
        $this->appr->approve($this->idRantai($id), $this->mudirBagian);
        $this->appr->approve($this->idRantai($id), $this->mudirUmum);

        $isi = preg_replace('/\s+/', ' ', $this->halaman($id)->content());

        // Inilah yang dijaga: nama BENAR menempel pada tahap yang BENAR.
        $this->assertMatchesRegularExpression('/Mudir Bagian.{0,220}Juainy Anwar/s', $isi);
        $this->assertMatchesRegularExpression('/Mudir Umum.{0,220}Kiki Kusumayadi/s', $isi);
        $this->assertStringNotContainsString('Mudir Bagian (disetujui Kiki', $isi);
    }

    public function test_verifikasi_keuangan_ikut_daftar_dan_menyebut_penyetujunya(): void
    {
        $id = $this->buat();
        $this->appr->approve($this->idRantai($id), $this->mudirBagian);
        $this->appr->approve($this->idRantai($id), $this->mudirUmum);

        // Rantai tuntas tetapi keuangan belum memverifikasi → barisnya menyebut
        // nama orang yang ditunggu, bukan sekadar "tim keuangan".
        $this->halaman($id)
            ->assertSee('Verifikasi keuangan')
            ->assertSee('Marullah Marzuq');
    }

    public function test_penolakan_menyebut_penolak_dan_verifikasi_tidak_berlanjut(): void
    {
        $id = $this->buat();
        $this->appr->reject($this->idRantai($id), $this->mudirBagian, 'Nota belum dilampirkan.');

        $this->halaman($id)
            ->assertSee('ditolak')
            ->assertSee('Juainy Anwar')
            ->assertSee('Nota belum dilampirkan.')
            ->assertSee('tidak berlanjut');
    }

    /** Usulan anggaran tak mengenal verifikasi keuangan — barisnya tak boleh muncul. */
    public function test_usulan_anggaran_tanpa_baris_verifikasi(): void
    {
        $t = ['status' => 'berjalan', 'tahap_sekarang' => 1, 'overbudget' => false, 'belum_dianggarkan' => false,
            'nominal' => '1000000', 'kode_bagian' => 'B1', 'nama_bagian' => 'Divisi Keuangan',
            'tahap' => [['urutan' => 1, 'nama_tahap' => 'Mudir Bagian', 'fungsi' => null, 'peringkat' => 3, 'nama_level_pengajuan' => 'Mudir Bagian', 'scope' => 'bagian']],
            'menunggu' => ['nama_tahap' => 'Mudir Bagian', 'peringkat' => 3, 'nama_level_pengajuan' => 'Mudir Bagian', 'fungsi' => null, 'scope' => 'bagian', 'kandidat' => ['Juainy Anwar']],
            'logs' => collect()];

        $html = view('pengajuan._timeline', ['t' => $t])->render();

        $this->assertStringNotContainsString('Verifikasi keuangan', $html);
        $this->assertStringContainsString('Juainy Anwar', $html);
    }
}
