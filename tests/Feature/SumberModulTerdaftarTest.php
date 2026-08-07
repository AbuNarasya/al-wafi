<?php

namespace Tests\Feature;

use App\Services\Ledger\SumberModul;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * SETIAP MODUL YANG MENJURNAL HARUS TERDAFTAR di SumberModul::ALL.
 *
 * Daftar itu menyebut dirinya "satu sumber kebenaran", tetapi tak ada apa pun
 * yang menegakkannya: `postJournal` menerima `sumber_modul` apa adanya, tanpa
 * memeriksa. Modul yang lupa didaftarkan karena itu berjalan normal — sampai
 * ketahuan bahwa unitnya tak pernah bisa disetel.
 *
 * Begitulah `PinjamanKaryawan` lolos: ia memanggil postJournal tanpa mengoper
 * `kode_unit`, sehingga PostingService jatuh ke `resolveDefaultUnit()`, yang
 * mencari di `unit_defaults` — tabel yang hanya bisa diisi lewat layar Unit
 * Default, dan layar itu hanya menawarkan isi SumberModul::ALL. Tidak terdaftar
 * berarti tidak bisa diisi, berarti seluruh baris jurnalnya lahir tanpa unit.
 *
 * Yang diperiksa hanya berkas yang benar-benar memanggil postJournal. `Budget`
 * misalnya juga punya `const SUMBER`, tetapi nilainya dipakai sebagai jenis
 * dokumen rantai persetujuan — bukan sumber jurnal, jadi ia memang tak boleh
 * muncul di layar Unit Default.
 */
class SumberModulTerdaftarTest extends TestCase
{
    public function test_setiap_sumber_modul_yang_menjurnal_terdaftar(): void
    {
        $belum = [];

        foreach ($this->berkasPhp(app_path()) as $berkas) {
            $isi = (string) file_get_contents($berkas);
            if (! str_contains($isi, 'postJournal')) {
                continue;
            }

            preg_match_all("/const\s+SUMBER\s*=\s*'([^']+)'/", $isi, $lewatKonstanta);
            preg_match_all("/'sumber_modul'\s*=>\s*'([^']+)'/", $isi, $lewatLiteral);

            foreach (array_merge($lewatKonstanta[1], $lewatLiteral[1]) as $nilai) {
                if (! SumberModul::isValid($nilai)) {
                    $belum[$nilai] = basename($berkas);
                }
            }
        }

        $this->assertSame([], $belum, sprintf(
            "Nilai sumber_modul berikut dipakai untuk menjurnal tapi tidak ada di SumberModul::ALL — "
            ."unit bisnis bawaannya tak bisa disetel dari layar Unit Default:\n%s",
            implode("\n", array_map(fn ($f, $v) => "  {$v} ({$f})", $belum, array_keys($belum))),
        ));
    }

    public function test_daftarnya_tidak_memuat_nilai_kembar(): void
    {
        $this->assertSame(
            array_values(array_unique(SumberModul::ALL)),
            SumberModul::ALL,
            'SumberModul::ALL memuat nilai kembar — layar Unit Default akan menampilkannya dua kali.',
        );
    }

    /** @return list<string> */
    private function berkasPhp(string $akar): array
    {
        $hasil = [];
        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($akar, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($iter as $berkas) {
            if ($berkas->isFile() && $berkas->getExtension() === 'php') {
                $hasil[] = $berkas->getPathname();
            }
        }

        return $hasil;
    }
}
