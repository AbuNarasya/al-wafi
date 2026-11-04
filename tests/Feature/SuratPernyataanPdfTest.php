<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\Level;
use App\Models\PersetujuanTerm;
use App\Models\Santri;
use App\Models\TermTemplate;
use App\Models\User;
use App\Models\Wali;
use App\Models\Jenjang;
use App\Models\JalurPendaftaran;
use App\Services\Modules\SuratPernyataanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PDF surat pernyataan (4 Nov 2026): diunggah petugas PPSB per versi (opsional, ≤ 2 MB),
 * terkunci bersama versinya, diunduh wali di portal — versi aktif saat mendaftar,
 * dan versi yang ia setujui di halaman anak.
 */
class SuratPernyataanPdfTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->admin = User::create([
            'username' => 'zzsp_admin', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif',
        ]);
        Jenjang::create(['kode' => 'J001', 'nama' => 'SDTQ', 'jumlah_tingkat' => 6]);
        JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler', 'status' => 'aktif']);
    }

    private function pdf(int $kb = 100): UploadedFile
    {
        return UploadedFile::fake()->create('Surat Pernyataan 2026.pdf', $kb, 'application/pdf');
    }

    private function isian(array $timpa = []): array
    {
        return $timpa + ['judul' => 'Surat Pernyataan', 'isi' => 'Patuh tata tertib.', 'berlaku_mulai' => '2026-11-01'];
    }

    private function keluargaDenganAnak(): array
    {
        $wali = Wali::create([
            'kontak_utama' => 'ayah', 'nama_ayah' => 'Bapak Hasan', 'telepon_ayah' => '081211110000',
            'email_ayah' => 'hasan@contoh.id', 'nama' => 'Bapak Hasan', 'telepon' => '081211110000', 'status' => 'aktif',
        ]);
        $santri = Santri::create([
            'no_pendaftaran' => 'ZZSP-1', 'nama' => 'Ahmad', 'jenis_kelamin' => 'L',
            'kode_jenjang' => 'J001', 'jalur' => 'reguler', 'status' => 'aktif', 'id_wali' => $wali->id,
        ]);

        return [$wali, $santri];
    }

    private function sebagaiWali(Wali $wali)
    {
        return $this->actingAs($wali, 'wali')->withSession(['portal.peran' => 'ayah', 'portal.punya_sandi' => true]);
    }

    public function test_petugas_mengunggah_pdf_dan_bisa_mengunduhnya(): void
    {
        $this->actingAs($this->admin)->post(route('surat_pernyataan.store'), $this->isian() + ['berkas_pdf' => $this->pdf()])
            ->assertRedirect(route('surat_pernyataan.index'));
        $surat = TermTemplate::sole();
        $this->assertSame('Surat Pernyataan 2026.pdf', $surat->berkas_pdf_nama);
        Storage::disk('local')->assertExists($surat->berkas_pdf);

        $this->actingAs($this->admin)->get(route('surat_pernyataan.index'))->assertOk()->assertSee(route('surat_pernyataan.pdf', $surat->id), false);
        $this->actingAs($this->admin)->get(route('surat_pernyataan.pdf', $surat->id))->assertOk();
    }

    public function test_pdf_opsional_hanya_pdf_dan_maksimal_2_mb(): void
    {
        $this->actingAs($this->admin)->post(route('surat_pernyataan.store'), $this->isian())->assertRedirect(route('surat_pernyataan.index'));
        $this->assertNull(TermTemplate::sole()->berkas_pdf);

        $this->actingAs($this->admin)->post(route('surat_pernyataan.store'), $this->isian() + ['berkas_pdf' => $this->pdf(2100)])
            ->assertSessionHasErrors('berkas_pdf');
        $this->actingAs($this->admin)->post(route('surat_pernyataan.store'), $this->isian() + ['berkas_pdf' => UploadedFile::fake()->create('a.docx', 10)])
            ->assertSessionHasErrors('berkas_pdf');
        $this->actingAs($this->admin)->post(route('surat_pernyataan.store'), $this->isian() + ['berkas_pdf' => $this->pdf(1900)])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, TermTemplate::count());
    }

    public function test_ganti_dan_hapus_pdf_sebelum_dipakai_lalu_terkunci(): void
    {
        $svc = new SuratPernyataanService;
        $surat = $svc->create($this->isian(), $this->admin->id_pengguna, $this->pdf());
        $lama = $surat->berkas_pdf;

        $surat = $svc->update($surat->id, $this->isian(), UploadedFile::fake()->create('Revisi.pdf', 50, 'application/pdf'));
        $this->assertSame('Revisi.pdf', $surat->berkas_pdf_nama);
        Storage::disk('local')->assertMissing($lama);

        $surat = $svc->update($surat->id, $this->isian(), null, true);
        $this->assertNull($surat->berkas_pdf);

        [$wali, $santri] = $this->keluargaDenganAnak();
        PersetujuanTerm::create(['id_santri' => $santri->id, 'id_wali' => $wali->id, 'id_term_template' => $surat->id,
            'isi_umum' => $surat->isi, 'hash_sha256' => hash('sha256', $surat->isi), 'status' => 'disetujui']);
        $this->expectException(AppException::class);
        $svc->update($surat->id, $this->isian(), $this->pdf());
    }

    public function test_wali_mengunduh_versi_aktif_dan_versi_yang_disetujuinya_saja(): void
    {
        $svc = new SuratPernyataanService;
        $v1 = $svc->create($this->isian(), $this->admin->id_pengguna, $this->pdf());
        $v2 = $svc->create($this->isian(['isi' => 'Revisi']), $this->admin->id_pengguna, $this->pdf());
        $v3 = $svc->create($this->isian(['isi' => 'Lain']), $this->admin->id_pengguna, $this->pdf());
        [$wali, $santri] = $this->keluargaDenganAnak();

        // v1 aktif → boleh; v2 belum aktif & tak disetujui → 404.
        $this->sebagaiWali($wali)->get(route('portal.surat_pdf', $v1->id))->assertOk();
        $this->sebagaiWali($wali)->get(route('portal.surat_pdf', $v2->id))->assertNotFound();

        // Setujui v2, lalu aktifkan v3: v2 tetap bisa diunduh, v1 kini tidak.
        PersetujuanTerm::create(['id_santri' => $santri->id, 'id_wali' => $wali->id, 'id_term_template' => $v2->id,
            'isi_umum' => $v2->isi, 'hash_sha256' => hash('sha256', $v2->isi), 'status' => 'disetujui']);
        $svc->aktifkan($v3->id);
        $this->sebagaiWali($wali)->get(route('portal.surat_pdf', $v2->id))->assertOk();
        $this->sebagaiWali($wali)->get(route('portal.surat_pdf', $v1->id))->assertNotFound();

        // Halaman anak menautkan versi yang disetujui.
        $this->sebagaiWali($wali)->get(route('portal.anak', $santri->id))->assertOk()
            ->assertSee(route('portal.surat_pdf', $v2->id), false);

        // Tanpa masuk → ke halaman masuk.
        auth('wali')->logout();
        $this->flushSession();
        $this->get(route('portal.surat_pdf', $v3->id))->assertRedirect(route('portal.masuk'));
    }
}
