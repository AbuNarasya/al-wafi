<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Komponen <x-input-rupiah>: isian nominal berpemisah ribuan.
 *
 * Yang dijaga di sini adalah sisi SERVER-nya — topeng saat mengetik ada di
 * `window.inputRupiah` (resources/js/app.js) dan tak terjangkau PHPUnit. Justru
 * sisi server yang paling mahal bila salah: hidden itulah yang benar-benar
 * terkirim, dan bila ia kosong, tagihan terbit tanpa nominal tanpa suara.
 */
class InputRupiahTest extends TestCase
{
    private function render(?string $value, string $extra = ''): string
    {
        return Blade::render(
            '<x-input-rupiah name="nominal" :value="$v" '.$extra.' />',
            ['v' => $value],
        );
    }

    /** Nominal dari basis data selalu berekor ".00" — yang dilihat petugas tidak boleh begitu. */
    public function test_nominal_ditampilkan_berpemisah_ribuan_tanpa_sen(): void
    {
        $html = $this->render('8000000.00');

        $this->assertStringContainsString('value="8.000.000"', $html);
        // Yang TERKIRIM tetap angka mentah — server tak mengenal pemisah ribuan.
        $this->assertStringContainsString('<input type="hidden" name="nominal" value="8000000"', $html);
        $this->assertStringNotContainsString('8000000.00', $html);
    }

    /**
     * Hidden-nya diisi server, bukan hanya oleh Alpine. Kalau JS gagal dimuat,
     * nilai bawaan harus tetap terkirim — bukan diam-diam menjadi kosong.
     */
    public function test_nilai_bawaan_terkirim_walau_tanpa_javascript(): void
    {
        $html = $this->render('25000000.00');

        $this->assertMatchesRegularExpression('/type="hidden" name="nominal" value="25000000"/', $html);
    }

    /** Nol adalah angka yang SAH (mis. bebas biaya) — beda dari kosong. */
    public function test_nol_dibedakan_dari_kosong(): void
    {
        $nol = $this->render('0.00');
        $this->assertStringContainsString('value="0"', $nol);
        $this->assertStringContainsString('name="nominal" value="0"', $nol);

        $kosong = $this->render(null);
        $this->assertStringContainsString('name="nominal" value=""', $kosong);
    }

    /**
     * `required` harus mendarat di isian TEKS, bukan di hidden: peramban
     * mengecualikan hidden dari validasi bawaannya, jadi menaruhnya di sana
     * menghapus penjaga "wajib diisi" tanpa jejak.
     */
    public function test_required_dipasang_pada_isian_yang_terlihat(): void
    {
        $html = $this->render('0.00', 'required');

        $this->assertMatchesRegularExpression('/type="text"[^>]*required/s', $html);
        $this->assertDoesNotMatchRegularExpression('/type="hidden"[^>]*required/s', $html);
    }

    /**
     * Penjaga STRUKTURAL untuk isian rupiah di luar komponen — baris berulang
     * (jurnal, cash in/out, invoice, termin) yang memakai `fmtRupiah()` langsung.
     *
     * Isian teks bertopeng TIDAK BOLEH membawa `name`: yang terkirim akan menjadi
     * "15.000.000" dan ditolak validasi `numeric` di server. Namanya harus ada di
     * hidden berisi state Alpine yang sudah bersih. Kesalahan ini pernah terjadi
     * dan tak terlihat sama sekali di layar — angkanya tampil rapi, totalnya pun
     * benar; yang gagal hanya penyimpanannya.
     */
    public function test_isian_rupiah_bertopeng_tidak_boleh_membawa_name(): void
    {
        $pelanggar = [];
        $berkas = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));

        foreach ($berkas as $f) {
            if (! str_ends_with((string) $f, '.blade.php')) {
                continue;
            }
            preg_match_all('/<input[^>]*inputmode="numeric"[^>]*>/', file_get_contents((string) $f), $m);
            foreach ($m[0] as $tag) {
                if (preg_match('/\s:?name="/', $tag)) {
                    $pelanggar[] = str_replace(resource_path('views').DIRECTORY_SEPARATOR, '', (string) $f);
                }
            }
        }

        $this->assertSame([], array_values(array_unique($pelanggar)),
            'Isian rupiah bertopeng membawa `name` — pindahkan namanya ke <input type="hidden"> berisi nilai mentah.');
    }
}
