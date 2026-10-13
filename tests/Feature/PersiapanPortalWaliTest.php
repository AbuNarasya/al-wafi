<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\HakAksesModul;
use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
use App\Models\Level;
use App\Models\PersetujuanTerm;
use App\Models\Santri;
use App\Models\TermTemplate;
use App\Models\User;
use App\Models\Wali;
use App\Services\Modules\KesiapanPortalWaliService;
use App\Services\Modules\SuratPernyataanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dua layar staf sebelum portal wali dibuka: Surat Pernyataan Wali (teks
 * berversi yang disetujui wali saat mendaftar) dan Kesiapan Portal Wali
 * (siapa yang sudah bisa masuk lewat email, siapa yang perlu dibereskan).
 */
class PersiapanPortalWaliTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->admin = User::create([
            'username' => 'zzpp_admin', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif',
        ]);
        Jenjang::create(['kode' => 'J001', 'nama' => 'SDTQ', 'jumlah_tingkat' => 6]);
        JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler', 'status' => 'aktif']);
    }

    private function surat(array $isi = []): TermTemplate
    {
        return (new SuratPernyataanService)->create($isi + [
            'judul' => 'Surat Pernyataan', 'isi' => "1. Patuh tata tertib.\r\n2. Membayar tepat waktu.",
            'berlaku_mulai' => '2026-10-12',
        ], $this->admin->id_pengguna);
    }

    private function setujui(TermTemplate $surat): void
    {
        $wali = $this->wali('Penyetuju', ['email_wali' => 'setuju@contoh.id']);
        $santri = $this->anak($wali, 'Anak Penyetuju');
        PersetujuanTerm::create([
            'id_santri' => $santri->id, 'id_wali' => $wali->id, 'id_term_template' => $surat->id,
            'isi_umum' => $surat->isi, 'hash_sha256' => hash('sha256', $surat->isi), 'status' => 'disetujui',
        ]);
    }

    private function wali(string $nama, array $kolom = []): Wali
    {
        static $n = 0;
        $telepon = '0812'.str_pad((string) ++$n, 7, '0', STR_PAD_LEFT);

        return Wali::create($kolom + [
            'kontak_utama' => 'wali', 'nama_wali' => $nama, 'telepon_wali' => $telepon,
            'nama' => $nama, 'telepon' => $telepon, 'status' => 'aktif',
        ]);
    }

    private function anak(Wali $wali, string $nama, string $status = 'aktif'): Santri
    {
        static $n = 0;

        return Santri::create([
            'no_pendaftaran' => 'ZZPP-'.(++$n), 'nama' => $nama, 'jenis_kelamin' => 'L',
            'kode_jenjang' => 'J001', 'jalur' => 'reguler', 'status' => $status, 'id_wali' => $wali->id,
        ]);
    }

    // ---- Surat pernyataan ----

    public function test_versi_bertambah_sendiri_dan_yang_pertama_langsung_aktif(): void
    {
        $v1 = $this->surat();
        $v2 = $this->surat(['judul' => 'Revisi']);

        $this->assertSame([1, 2], [$v1->versi, $v2->versi]);
        $this->assertSame('aktif', $v1->refresh()->status, 'tanpa versi aktif portal tak punya surat sama sekali');
        $this->assertSame('nonaktif', $v2->status, 'versi baru menunggu diaktifkan petugas');
        $this->assertSame($v1->id, SuratPernyataanService::aktif()->id);
        $this->assertStringNotContainsString("\r", $v1->isi, 'baris baru diseragamkan sebelum kelak di-hash');
    }

    public function test_mengaktifkan_satu_versi_menonaktifkan_yang_lain(): void
    {
        $v1 = $this->surat();
        $v2 = $this->surat();

        (new SuratPernyataanService)->aktifkan($v2->id);

        $this->assertSame('nonaktif', $v1->refresh()->status);
        $this->assertSame('aktif', $v2->refresh()->status);
        $this->assertSame(1, TermTemplate::where('status', 'aktif')->count());
    }

    public function test_versi_yang_sudah_disetujui_terkunci(): void
    {
        $v1 = $this->surat();
        $this->setujui($v1);

        try {
            (new SuratPernyataanService)->update($v1->id, ['judul' => 'X', 'isi' => 'Diubah diam-diam', 'berlaku_mulai' => '2026-10-12']);
            $this->fail('versi yang sudah disetujui tak boleh diubah');
        } catch (AppException $e) {
            $this->assertStringContainsString('versi baru', $e->getMessage());
        }

        // Tetap boleh diaktifkan ulang — itu tak mengubah teksnya.
        $v2 = $this->surat();
        (new SuratPernyataanService)->aktifkan($v2->id);
        (new SuratPernyataanService)->aktifkan($v1->id);
        $this->assertSame('aktif', $v1->refresh()->status);
    }

    public function test_versi_aktif_atau_terpakai_tak_bisa_dihapus(): void
    {
        $v1 = $this->surat();
        $v2 = $this->surat();

        $this->expectException(AppException::class);
        try {
            (new SuratPernyataanService)->remove($v1->id);
        } finally {
            // Versi yang belum dipakai & tak aktif boleh dihapus.
            (new SuratPernyataanService)->remove($v2->id);
            $this->assertNull(TermTemplate::find($v2->id));
        }
    }

    public function test_layar_surat_pernyataan_butuh_modulnya_sendiri(): void
    {
        $petugas = User::create([
            'username' => 'zzpp_ptg', 'nama' => 'Petugas PSB', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif',
        ]);
        $this->actingAs($petugas)->get(route('surat_pernyataan.index'))->assertForbidden();

        HakAksesModul::create([
            'id_pengguna' => $petugas->id_pengguna, 'kode_modul' => 'surat-pernyataan',
            'lihat' => true, 'buat' => true, 'ubah' => false, 'hapus' => false, 'menu' => true,
        ]);
        $this->surat();

        $this->actingAs($petugas)->get(route('surat_pernyataan.index'))
            ->assertOk()->assertSee('Membayar tepat waktu.')->assertDontSee('Jadikan Aktif');
        $this->actingAs($petugas)->post(route('surat_pernyataan.store'), [
            'judul' => 'Revisi', 'isi' => 'Isi baru', 'berlaku_mulai' => '2026-11-01',
        ])->assertRedirect(route('surat_pernyataan.index'));
        $this->assertSame(2, TermTemplate::count());
    }

    // ---- Kesiapan portal ----

    public function test_kesiapan_memisahkan_siap_kembar_dan_tanpa_email(): void
    {
        $siap = $this->wali('Siap', ['email_wali' => 'siap@contoh.id']);
        $this->anak($siap, 'Anak Siap');

        // Alamat yang sama di dua keluarga — huruf besar & spasi tak membuatnya berbeda.
        $kembarA = $this->wali('Kembar A', ['email_ayah' => 'sama@contoh.id']);
        $kembarB = $this->wali('Kembar B', ['email_wali' => '  SAMA@contoh.id ']);
        $this->anak($kembarA, 'Anak A');
        $this->anak($kembarB, 'Anak B', 'calon');

        $tanpa = $this->wali('Tanpa Email');
        $this->anak($tanpa, 'Anak Tanpa Email', 'terverifikasi');

        // Alumni tanpa email tak dihitung kurang — ia tak perlu masuk portal.
        $this->anak($this->wali('Alumni'), 'Sudah Lulus', 'alumni');

        // Ayah & ibu SATU keluarga berbagi alamat bukan kembar.
        $berdua = $this->wali('Berdua', ['email_ayah' => 'rumah@contoh.id', 'email_ibu' => 'rumah@contoh.id']);
        $this->anak($berdua, 'Anak Berdua');

        $r = (new KesiapanPortalWaliService)->ringkasan();

        $this->assertSame(5, $r['perlu']);
        $this->assertSame(2, $r['siap'], 'Siap & Berdua');
        $this->assertSame(['sama@contoh.id'], array_column($r['kembar'], 'email'));
        $this->assertEqualsCanonicalizing([$kembarA->id, $kembarB->id], array_column($r['kembar'][0]['keluarga'], 'id'));
        $this->assertSame([$tanpa->id], array_column($r['tanpa_email'], 'id'));
        $this->assertSame('ANAK TANPA EMAIL', $r['tanpa_email'][0]['anak'][0]['nama']);
    }

    public function test_layar_kesiapan_dan_kembali_ke_sana_setelah_menyunting(): void
    {
        $tanpa = $this->wali('Keluarga Tanpa Email');
        $this->anak($tanpa, 'Anak Tanpa Email');

        $this->actingAs($this->admin)->get(route('wali.kesiapan_portal'))
            ->assertOk()->assertSee('Keluarga Tanpa Email')->assertSee('ANAK TANPA EMAIL');

        $this->actingAs($this->admin)->put(route('wali.update', $tanpa->id), [
            'dari' => 'kesiapan', 'kontak_utama' => 'wali', 'nama_wali' => 'Keluarga Tanpa Email',
            'telepon_wali' => $tanpa->telepon, 'email_wali' => 'baru@contoh.id', 'status' => 'aktif',
        ])->assertRedirect(route('wali.kesiapan_portal'));

        $this->assertSame([], (new KesiapanPortalWaliService)->ringkasan()['tanpa_email']);
    }
}
