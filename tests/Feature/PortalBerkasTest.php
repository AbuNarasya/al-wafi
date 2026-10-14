<?php

namespace Tests\Feature;

use App\Models\DokumenSantri;
use App\Models\Jenjang;
use App\Models\Level;
use App\Models\Notification as NotifikasiStaf;
use App\Models\NotifikasiWali;
use App\Models\Santri;
use App\Models\User;
use App\Models\Wali;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Berkas PSB lewat Portal Wali (keputusan user 15 Okt 2026): daftar periksa yang
 * diingatkan (bukan penghalang); staf memutus per jenis — "diterima" boleh tanpa
 * unggahan (diserahkan fisik), "perlu diganti" wajib beralasan & dikabarkan.
 */
class PortalBerkasTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Wali $wali;

    private Santri $ahmad;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->admin = User::create(['username' => 'zzpb_admin', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif']);
        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'jumlah_tingkat' => 3]);
        $this->wali = Wali::create(['kontak_utama' => 'ibu', 'nama_ibu' => 'Ibu Aminah', 'telepon_ibu' => '0811111111',
            'email_ibu' => 'aminah@contoh.id', 'nama_ayah' => 'Bapak Hasan', 'email_ayah' => 'hasan@contoh.id',
            'nama' => 'Ibu Aminah', 'telepon' => '0811111111', 'status' => 'aktif']);
        $this->ahmad = Santri::create(['no_pendaftaran' => 'ZZPB-1', 'nama' => 'Ahmad', 'jenis_kelamin' => 'L',
            'kode_jenjang' => 'SMP', 'jalur' => 'reguler', 'status' => 'calon', 'id_wali' => $this->wali->id]);
    }

    private function sebagai(string $peran = 'ibu'): static
    {
        return $this->actingAs($this->wali, 'wali')->withSession(['portal.peran' => $peran, 'portal.punya_sandi' => true]);
    }

    private function unggah(string $jenis = 'kartu_keluarga', ?UploadedFile $berkas = null)
    {
        return $this->sebagai()->post(route('portal.berkas', $this->ahmad->id), [
            'jenis' => $jenis, 'berkas' => $berkas ?? UploadedFile::fake()->image('kk.jpg', 800, 600)->size(200),
        ]);
    }

    public function test_daftar_periksa_dan_pengingat_tanpa_berkas_pasca_lulus_untuk_calon(): void
    {
        $this->sebagai()->get(route('portal.berkas', $this->ahmad->id))->assertOk()
            ->assertSee('Kartu Keluarga')->assertSee('Akta Kelahiran')->assertSee('6 dari 6 berkas registrasi')
            ->assertDontSee('Hasil Med Check');
        $this->sebagai()->get(route('portal.anak', $this->ahmad->id))->assertOk()->assertSee('6 dari 6 berkas registrasi belum diterima');
        $this->sebagai()->get(route('portal.beranda'))->assertOk()->assertSee('berkas registrasi belum lengkap');
    }

    public function test_wali_mengunggah_staf_dikabari_dan_melihatnya(): void
    {
        $this->unggah()->assertRedirect(route('portal.berkas', $this->ahmad->id))->assertSessionHas('status');

        $dok = DokumenSantri::sole();
        $this->assertSame($this->wali->id, (int) $dok->diunggah_wali);
        $this->assertSame('ibu', $dok->diunggah_peran);
        $this->assertNull($dok->diunggah_oleh);
        Storage::disk('local')->assertExists($dok->path);

        $this->sebagai()->get(route('portal.berkas', $this->ahmad->id))->assertSee('Menunggu diperiksa');
        $notif = NotifikasiStaf::where('jenis', 'berkas_wali_diunggah')->sole();
        $this->assertSame('/santri/'.$this->ahmad->id.'/dokumen', (new \App\Services\Modules\NotificationService)->tautan($notif));

        $this->actingAs($this->admin)->get(route('dokumen_santri.index', $this->ahmad->id))->assertOk()
            ->assertSee('diunggah ibu lewat portal')->assertSee('Menunggu diperiksa');
    }

    public function test_staf_menerima_tanpa_unggahan_dan_wali_tak_bisa_menimpanya(): void
    {
        $this->actingAs($this->admin)->post(route('dokumen_santri.putuskan', [$this->ahmad->id, 'akta_kelahiran']), ['status' => 'diterima'])
            ->assertSessionHas('status');

        $this->sebagai()->get(route('portal.berkas', $this->ahmad->id))->assertSee('diserahkan langsung');
        $this->unggah('akta_kelahiran')->assertSessionHas('error');
        $this->assertSame(0, DokumenSantri::count());
    }

    public function test_perlu_diganti_wajib_alasan_dikabarkan_lalu_unggahan_pengganti_kembali_menunggu(): void
    {
        $this->unggah();
        $url = route('dokumen_santri.putuskan', [$this->ahmad->id, 'kartu_keluarga']);
        $this->actingAs($this->admin)->post($url, ['status' => 'perlu_diganti'])->assertSessionHas('error');
        $this->actingAs($this->admin)->post($url, ['status' => 'perlu_diganti', 'catatan' => 'Foto buram'])->assertSessionHas('status');

        $this->assertEqualsCanonicalizing(['ayah', 'ibu'], NotifikasiWali::where('jenis', 'berkas_perlu_diganti')->pluck('peran')->all());
        $this->sebagai()->get(route('portal.berkas', $this->ahmad->id))->assertSee('Perlu diganti')->assertSee('Foto buram');

        $this->unggah('kartu_keluarga', UploadedFile::fake()->image('kk-baru.jpg')->size(150));
        $this->sebagai()->get(route('portal.berkas', $this->ahmad->id))->assertSee('Menunggu diperiksa')->assertDontSee('Foto buram');
    }

    public function test_unggahan_staf_langsung_diterima(): void
    {
        $this->actingAs($this->admin)->post(route('dokumen_santri.store', $this->ahmad->id), [
            'jenis' => 'foto', 'berkas' => UploadedFile::fake()->image('foto.jpg')->size(100),
        ])->assertSessionHas('status');

        $this->sebagai()->get(route('portal.berkas', $this->ahmad->id))->assertSee('5 dari 6 berkas registrasi');
    }

    public function test_wali_menghapus_unggahannya_selama_belum_diterima(): void
    {
        $this->unggah();
        $dok = DokumenSantri::sole();

        $this->sebagai()->delete(route('portal.berkas.hapus', $dok->id))->assertSessionHas('status');
        $this->assertSame(0, DokumenSantri::count());
        Storage::disk('local')->assertMissing($dok->path);
    }

    public function test_keluarga_lain_tak_bisa_melihat_atau_mengunggah(): void
    {
        $this->unggah();
        $dok = DokumenSantri::sole();
        $lain = Wali::create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Lain', 'telepon_ayah' => '0822', 'email_ayah' => 'lain@contoh.id',
            'nama' => 'Lain', 'telepon' => '0822', 'status' => 'aktif']);
        $sesiLain = $this->actingAs($lain, 'wali')->withSession(['portal.peran' => 'ayah', 'portal.punya_sandi' => true]);

        $sesiLain->get(route('portal.berkas', $this->ahmad->id))->assertNotFound();
        $sesiLain->get(route('portal.berkas.lihat', $dok->id))->assertNotFound();
        $sesiLain->post(route('portal.berkas', $this->ahmad->id), ['jenis' => 'foto', 'berkas' => UploadedFile::fake()->image('x.jpg')])->assertNotFound();
        $sesiLain->delete(route('portal.berkas.hapus', $dok->id))->assertSessionHas('error');
        $this->assertSame(1, DokumenSantri::count());
    }
}
