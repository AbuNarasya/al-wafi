<?php

namespace Tests\Feature;

use App\Models\DokumenSantri;
use App\Models\Jenjang;
use App\Models\Santri;
use App\Models\Wali;
use App\Services\PortalWali\KataSandiWali;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AUDIT 22 Okt 2026 — XSS tersimpan lewat berkas: berkas "akta.pdf" berisi HTML dikirim dengan header
 * Content-Type text/html. Dulu tercatat & disajikan sebagai text/html (skrip jalan di domain ERP saat staf
 * membukanya). Kini tipe diambil dari ISI berkas dan penyajian hanya meloloskan gambar & PDF.
 */
class BerkasAmanTest extends TestCase
{
    use RefreshDatabase;

    private function unggah(string $isi, string $nama, string $mimeKlien): DokumenSantri
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        Notification::fake();
        Storage::fake('local');
        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'jumlah_tingkat' => 3]);
        $w = Wali::create(['kontak_utama' => 'ibu', 'nama_ibu' => 'Ibu', 'telepon_ibu' => '081234567891', 'email_ibu' => 'i@contoh.id',
            'nama_ayah' => 'Ayah', 'nama' => 'Ibu', 'telepon' => '081234567891', 'status' => 'aktif']);
        $s = Santri::create(['no_pendaftaran' => 'ZZBA-1', 'nama' => 'Anak', 'jenis_kelamin' => 'L', 'kode_jenjang' => 'SMP',
            'jalur' => 'reguler', 'status' => 'calon', 'id_wali' => $w->id]);
        (new KataSandiWali)->simpan($w, 'ibu', 'sandiKorban1', 'sandiKorban1');
        $this->post(route('portal.masuk.sandi'), ['email' => 'i@contoh.id', 'sandi' => 'sandiKorban1']);

        $tmp = tempnam(sys_get_temp_dir(), 'ba');
        file_put_contents($tmp, $isi);
        $this->post(route('portal.berkas', $s->id), ['jenis' => 'akta_kelahiran', 'berkas' => new UploadedFile($tmp, $nama, $mimeKlien, null, true)]);

        return DokumenSantri::firstOrFail();
    }

    public function test_pdf_berisi_html_tak_pernah_disajikan_sebagai_html(): void
    {
        $d = $this->unggah("%PDF-1.4\n<html><script>alert(document.domain)</script></html>\n%%EOF", 'akta.pdf', 'text/html');

        $this->assertNotSame('text/html', $d->mime, 'tipe dicatat dari isi berkas, bukan header peramban');
        $r = $this->get(route('portal.berkas.lihat', $d->id))->assertOk();
        $this->assertStringNotContainsString('html', (string) $r->headers->get('Content-Type'));
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', (string) $r->headers->get('Content-Security-Policy'));
    }

    public function test_gambar_asli_tetap_tampil_inline(): void
    {
        $tmp = UploadedFile::fake()->image('kk.jpg', 50, 50);
        $d = $this->unggah((string) file_get_contents($tmp->getRealPath()), 'kk.jpg', 'image/jpeg');

        $r = $this->get(route('portal.berkas.lihat', $d->id))->assertOk();
        $this->assertSame('image/jpeg', $r->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', (string) $r->headers->get('Content-Disposition'));
    }
}
