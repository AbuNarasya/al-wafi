<?php

/**
 * Cetak berkas Markdown mana pun di repo ini menjadi PDF siap edar.
 *
 * Jalankan dari akar proyek:
 *     C:\php\php.exe docs/render-markdown.php DEPLOY-HOSTINGER.md
 *     C:\php\php.exe docs/render-markdown.php CLAUDE.md --simpan
 *
 * Hasilnya masuk ke `docs/` dengan nama yang sama berekstensi .pdf.
 *
 * Seperti render-rancangan.php, mesin cetaknya **Chrome headless**, bukan dompdf
 * — dompdf tak mengerti flexbox maupun custom property, dan tabel di dokumen
 * kerja kita banyak. Yang berbeda: sumbernya Markdown, jadi ada satu tahap
 * tambahan (CommonMark → HTML) sebelum gaya cetak disisipkan.
 *
 * Ekstensi GFM dipakai supaya **tabel** ikut terbaca; tanpa itu seluruh tabel
 * di panduan deploy keluar sebagai baris teks berpipa yang tak ada gunanya.
 */
require __DIR__.'/../vendor/autoload.php';

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;

$argumen = array_values(array_filter(
    array_slice($argv, 1),
    static fn ($a) => ! str_starts_with($a, '--'),
));

if ($argumen === []) {
    fwrite(STDERR, "Sebutkan berkas Markdown-nya, mis: docs/render-markdown.php DEPLOY-HOSTINGER.md\n");
    exit(1);
}

$sumber = $argumen[0];
if (! is_file($sumber)) {
    $sumber = __DIR__.'/../'.$argumen[0];
}
if (! is_file($sumber)) {
    fwrite(STDERR, "Sumber tidak ditemukan: {$argumen[0]}\n");
    exit(1);
}

$dasar = pathinfo($sumber, PATHINFO_FILENAME);
$keluar = __DIR__.'/'.$dasar.'.pdf';
$sementara = __DIR__.'/.cetak-'.strtolower($dasar).'.html';

$chrome = null;
foreach ([
    'C:\Program Files\Google\Chrome\Application\chrome.exe',
    'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe',
    'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',
    'C:\Program Files\Microsoft\Edge\Application\msedge.exe',
] as $kandidat) {
    if (is_file($kandidat)) {
        $chrome = $kandidat;
        break;
    }
}
if ($chrome === null) {
    fwrite(STDERR, "Chrome/Edge tidak ditemukan — tak ada mesin render untuk mencetak berkas ini.\n");
    exit(1);
}

$lingkungan = new Environment([
    'html_input' => 'allow',          // dokumen kita memuat <details>/<summary>
    'allow_unsafe_links' => false,
]);
$lingkungan->addExtension(new CommonMarkCoreExtension);
$lingkungan->addExtension(new GithubFlavoredMarkdownExtension);

$markdown = file_get_contents($sumber);
$isi = (string) (new MarkdownConverter($lingkungan))->convert($markdown);

// Judul dokumen diambil dari H1 pertama; kalau tak ada, pakai nama berkasnya.
$judul = preg_match('/^#\s+(.+)$/m', $markdown, $c) === 1 ? trim($c[1]) : $dasar;

// PHP CLI di mesin ini berjalan pada UTC, jadi pagi WIB masih terhitung hari
// kemarin — tanggal cetak yang meleset sehari bikin dokumen tampak basi.
$tanggal = (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('j F Y');
$bulan = [
    'January' => 'Januari', 'February' => 'Februari', 'March' => 'Maret',
    'April' => 'April', 'May' => 'Mei', 'June' => 'Juni', 'July' => 'Juli',
    'August' => 'Agustus', 'September' => 'September', 'October' => 'Oktober',
    'November' => 'November', 'December' => 'Desember',
];
$tanggal = strtr($tanggal, $bulan);

/**
 * Gaya cetak. Warnanya mengikuti dokumen `docs/` yang lain supaya satu map ini
 * terlihat sebagai satu keluarga, bukan kumpulan berkas dari tempat berbeda.
 *
 * Yang paling menentukan hasilnya bukan warna melainkan `break-inside: avoid`
 * pada tabel & blok kode: tabel gejala→sebab yang terbelah dua halaman kehilangan
 * barisnya di lipatan, dan justru bagian itulah yang dibaca saat panik.
 */
$gaya = <<<'CSS'
@page { size: A4; margin: 15mm 14mm 16mm; }
html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }

* { box-sizing: border-box; }

body {
  margin: 0;
  background: #fff;
  color: #1a2130;
  font: 10.5pt/1.6 "Segoe UI", system-ui, -apple-system, sans-serif;
}

/* ---- Kepala dokumen ---- */
.kop {
  border-bottom: 2.5pt solid #164a9e;
  padding-bottom: 7pt;
  margin-bottom: 16pt;
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12pt;
}
.kop .nama { font-size: 10pt; font-weight: 700; color: #164a9e; letter-spacing: .04em; text-transform: uppercase; }
.kop .tgl  { font-size: 8.5pt; color: #6b7688; }

/* ---- Tipografi ---- */
h1, h2, h3, h4 { color: #0e3168; line-height: 1.25; break-after: avoid; }

/* Tiap H1 memulai halaman baru: dokumen kerja begini dibaca sambil tangan
   sibuk, dan bagian yang dimulai di kaki halaman membuat orang kehilangan
   jejak sudah sampai mana. Judul dokumen sendiri dikecualikan — ia duduk
   tepat sesudah kop, jadi cukup dikenali lewat pemilih itu. */
h1 {
  font-size: 21pt;
  margin: 0 0 4pt;
  letter-spacing: -.01em;
  break-before: page;
  padding-bottom: 5pt;
  border-bottom: 1.6pt solid #164a9e;
}
.kop + h1 { break-before: avoid; border-bottom: 0; padding-bottom: 0; }
h2 {
  font-size: 14pt; margin: 20pt 0 7pt;
  padding-bottom: 3pt; border-bottom: .8pt solid #d8dde5;
}
h3 { font-size: 11.5pt; margin: 14pt 0 5pt; }
h4 { font-size: 10.5pt; margin: 11pt 0 4pt; color: #4e5a6e; }
p  { margin: 0 0 7pt; }

a { color: #164a9e; text-decoration: none; }

strong { color: #0e3168; }

hr { border: 0; border-top: .8pt solid #d8dde5; margin: 16pt 0; }

ul, ol { margin: 0 0 8pt; padding-left: 17pt; }
li { margin-bottom: 3pt; }
li > ul, li > ol { margin-top: 3pt; }

/* ---- Kode ---- */
code {
  font-family: Consolas, "Cascadia Mono", monospace;
  font-size: 9pt;
  background: #f1f3f7;
  border: .5pt solid #e2e6ee;
  border-radius: 3pt;
  padding: .8pt 3pt;
  color: #0e3168;
}
pre {
  background: #f7f8fb;
  border: .7pt solid #dfe4ec;
  border-left: 2.5pt solid #164a9e;
  border-radius: 4pt;
  padding: 8pt 10pt;
  margin: 0 0 9pt;
  overflow: visible;
  white-space: pre-wrap;      /* di kertas tak ada yang bisa digeser */
  word-break: break-word;
  break-inside: avoid;
}
pre code { background: none; border: 0; padding: 0; font-size: 9pt; color: #1a2130; }

/* ---- Tabel ---- */
table {
  width: 100%;
  border-collapse: collapse;
  margin: 0 0 11pt;
  font-size: 9.5pt;
  break-inside: avoid;
}
thead { background: #eef2f9; }
th {
  text-align: left;
  font-weight: 700;
  color: #0e3168;
  padding: 5pt 7pt;
  border: .6pt solid #cfd6e2;
}
td {
  padding: 5pt 7pt;
  border: .6pt solid #dde2eb;
  vertical-align: top;
}
tbody tr:nth-child(even) { background: #fafbfd; }
td code, th code { font-size: 8.5pt; }

/* ---- Kutipan / peringatan ---- */
blockquote {
  margin: 0 0 10pt;
  padding: 8pt 11pt;
  background: #fdf5e3;
  border-left: 2.5pt solid #a16207;
  border-radius: 0 4pt 4pt 0;
  color: #5a4408;
  break-inside: avoid;
}
blockquote p:last-child { margin-bottom: 0; }

/* Baris yang diawali tanda seru segitiga diperlakukan sebagai peringatan,
   sebab di sumbernya ia memang dipakai begitu — bukan sekadar hiasan. */
.awas {
  background: #fdecea;
  border-left: 2.5pt solid #b42318;
  border-radius: 0 4pt 4pt 0;
  padding: 7pt 11pt;
  margin: 0 0 9pt;
  color: #7a1c14;
  break-inside: avoid;
}
.awas strong { color: #8c1d13; }

details { margin: 0 0 9pt; }
summary { font-weight: 600; color: #0e3168; cursor: default; }

img { max-width: 100%; }
CSS;

// Paragraf berawalan ⚠️ dinaikkan jadi blok peringatan merah.
$isi = preg_replace('/<p>(⚠️)/u', '<p class="awas">$1', $isi);

$halaman = '<!doctype html><html lang="id"><head><meta charset="utf-8">'
    ."<title>{$judul}</title><style>{$gaya}</style></head><body>"
    .'<div class="kop"><span class="nama">Al Wafi ERP</span>'
    ."<span class=\"tgl\">Dicetak {$tanggal}</span></div>"
    .$isi
    .'</body></html>';

file_put_contents($sementara, $halaman);

/**
 * DUA bendera untuk menekan kepala & kaki cetak, bukan satu.
 *
 * `--print-to-pdf-no-header` sudah tak dikenali Chrome baru (di mesin ini 153),
 * dan akibatnya tak kentara: PDF-nya tetap jadi, hanya saja tiap halaman
 * membawa tanggal, judul jendela, dan ALAMAT BERKAS LOKAL di kakinya — ikut
 * tercetak dan ikut tersebar. Penggantinya `--no-pdf-header-footer`. Keduanya
 * disebut sekaligus supaya skrip ini tetap benar di Chrome lama maupun baru;
 * bendera yang tak dikenal diabaikan begitu saja.
 */
$perintah = sprintf(
    '"%s" --headless --disable-gpu --no-sandbox --run-all-compositor-stages-before-draw '
    .'--virtual-time-budget=6000 --no-pdf-header-footer --print-to-pdf-no-header '
    .'--print-to-pdf="%s" "%s"',
    $chrome,
    $keluar,
    'file:///'.str_replace('\\', '/', $sementara),
);

exec($perintah.' 2>&1', $keluaran, $kode);

if (in_array('--simpan', $argv, true)) {
    echo "Salinan cetak ditahan: {$sementara}\n";
} else {
    @unlink($sementara);
}

if ($kode !== 0 || ! is_file($keluar)) {
    fwrite(STDERR, "Gagal mencetak.\n".implode("\n", $keluaran)."\n");
    exit(1);
}

printf("%s → %s (%s KB)\n", basename($sumber), basename($keluar), number_format(filesize($keluar) / 1024, 0, ',', '.'));
