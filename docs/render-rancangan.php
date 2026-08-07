<?php

/**
 * Render rancangan Tagihan Lain-lain dari sumber HTML-nya menjadi PDF siap edar.
 *
 * Jalankan dari akar proyek:
 *     C:\php\php.exe docs/render-rancangan.php
 *
 * BUKAN dompdf seperti render-manual.php. Berkas ini memuat mockup layar yang
 * memakai flexbox, CSS grid, dan custom property — tiga hal yang tak dimengerti
 * dompdf, sehingga hasilnya jadi tumpukan blok tanpa bentuk. Karena itu ia
 * dicetak lewat Chrome headless yang memang mesin render sungguhan.
 *
 * Skripnya hanya menyiapkan salinan cetak (memaksa tema terang, mengunci lebar
 * A4, mencegah kartu terpotong antar-halaman) lalu memanggil Chrome. Sumbernya
 * sendiri tidak disentuh.
 *
 * Ada DUA rancangan yang hidup berdampingan: v2 (dua keluarga — laundry berbasis
 * pemakaian & kegiatan berbasis kepesertaan) yang berlaku, dan v1 yang disimpan
 * sebagai riwayat keputusan. Tanpa argumen yang dicetak adalah v2:
 *
 *     C:\php\php.exe docs/render-rancangan.php        → v2
 *     C:\php\php.exe docs/render-rancangan.php v1     → v1
 */
$daftar = [
    'v2' => ['rancangan-tagihan-lain-v2.html', 'Rancangan-Tagihan-Lain-lain-v2.pdf'],
    'v1' => ['rancangan-tagihan-lain.html', 'Rancangan-Tagihan-Lain-lain.pdf'],
];

// Argumen berawalan `--` adalah saklar (mis. --simpan), bukan pilihan versi.
$pilihan = 'v2';
foreach (array_slice($argv, 1) as $arg) {
    if (! str_starts_with($arg, '--')) {
        $pilihan = $arg;
        break;
    }
}
if (! isset($daftar[$pilihan])) {
    fwrite(STDERR, "Versi \"{$pilihan}\" tidak dikenal. Pilihan: ".implode(', ', array_keys($daftar))."\n");
    exit(1);
}

[$namaSumber, $namaKeluar] = $daftar[$pilihan];
$sumber = __DIR__.'/'.$namaSumber;
$keluar = __DIR__.'/'.$namaKeluar;
$sementara = __DIR__.'/.cetak-rancangan.html';

if (! is_file($sumber)) {
    fwrite(STDERR, "Sumber tidak ditemukan: {$sumber}\n");
    exit(1);
}

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

/**
 * Gaya khusus cetak. Disisipkan SETELAH gaya asli, jadi deklarasi yang sama
 * menang tanpa perlu menaikkan spesifisitas — termasuk mengembalikan token
 * warna ke tema terang, mengalahkan blok prefers-color-scheme di atasnya.
 */
$gayaCetak = <<<'CSS'
<style>
  @page { size: A4; margin: 13mm 11mm; }
  html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }

  /* Kembalikan ke tema terang apa pun preferensi mesin yang mencetak. */
  :root {
    --ground: #ffffff; --surface: #ffffff; --ink: #1a2130; --ink-soft: #4e5a6e;
    --ink-faint: #6b7688; --rule: #d8dde5; --rule-soft: #eceff4;
    --brand: #164a9e; --brand-dark: #0e3168; --brand-soft: #e8edf6; --accent: #f8c400;
    --ok: #047857; --ok-soft: #e7f5ef; --warn: #a16207; --warn-soft: #fdf5e3;
    --bad: #b42318; --bad-soft: #fdecea;
  }

  body { background: #fff; font-size: 10.5pt; line-height: 1.55; }
  .wrap { max-width: none; padding: 0; gap: 1.5rem; }
  .prose { max-width: none; }
  .callout { max-width: none; }

  h1 { font-size: 24pt; }
  h2 { font-size: 15pt; }
  h3 { font-size: 11.5pt; }
  .lede { font-size: 11.5pt; }

  /* Jangan sampai satu kartu terbelah dua halaman — mockup yang terpotong
     kepalanya justru jadi bahan salah paham saat dibahas beramai-ramai. */
  .shot, .flowcard, .callout, .tablebox, .q, .sec-head { break-inside: avoid; }
  section { break-inside: auto; }
  .screen { break-inside: avoid; box-shadow: none; }

  /* Di layar tabel mockup boleh digeser; di kertas tak ada yang bisa digeser,
     jadi selnya dibiarkan membungkus. */
  .stage { overflow: visible; }
  .screen { font-size: 9pt; }
  table.ui th, table.ui td { white-space: normal; }
  table.doc { min-width: 0; }
  .tablebox { overflow: visible; }
</style>
CSS;

/**
 * Tambahan khusus v2. Ia memakai token yang tak dikenal v1, dan satu di antaranya
 * BERTABRAKAN artinya: `--accent` di v1 adalah kuning terang dekoratif, sedangkan
 * di v2 ia warna TEKS emas tua yang duduk di atas `--accent-soft`. Menyamakan
 * keduanya membuat penanda keluarga B jadi kuning di atas kuning — tak terbaca.
 */
$gayaV2 = <<<'CSS'
<style>
  :root {
    --surface-sunk: #fbfbfd;
    --accent: #9a6b00; --accent-bright: #f8c400; --accent-soft: #fdf4dc;
  }
  /* Blok berpenanda keluarga (.keyed) sengaja TIDAK dikunci: bagian Laundry
     memuat tiga panel sekaligus dan takkan muat dalam satu halaman. */
  .panel, .calc, .flowcard { break-inside: avoid; }
  .panel table th, .panel table td { white-space: normal; }
  table { min-width: 0; }
</style>
CSS;

$html = file_get_contents($sumber);
$penanda = '<div class="wrap">';
if (! str_contains($html, $penanda)) {
    fwrite(STDERR, "Penanda \"{$penanda}\" tak ditemukan — sumbernya berubah bentuk.\n");
    exit(1);
}

$cetak = '<!doctype html><html lang="id"><head><meta charset="utf-8">'
    .str_replace($penanda, $gayaCetak.($pilihan === 'v2' ? $gayaV2 : '').'</head><body>'.$penanda, $html)
    .'</body></html>';

file_put_contents($sementara, $cetak);

$perintah = sprintf(
    '"%s" --headless --disable-gpu --no-sandbox --run-all-compositor-stages-before-draw '
    .'--virtual-time-budget=6000 --print-to-pdf-no-header --print-to-pdf="%s" "%s"',
    $chrome, $keluar, 'file:///'.str_replace('\\', '/', $sementara),
);

exec($perintah.' 2>&1', $keluaran, $kode);

// `--simpan` menahan salinan cetaknya supaya bisa dibuka di browser saat
// menyetel gaya cetak — tanpa itu tak ada cara melihat apa yang dicetak.
if (in_array('--simpan', $argv, true)) {
    echo "Salinan cetak ditahan: {$sementara}\n";
} else {
    @unlink($sementara);
}

if ($kode !== 0 || ! is_file($keluar)) {
    fwrite(STDERR, "Gagal mencetak (kode {$kode}):\n".implode("\n", $keluaran)."\n");
    exit(1);
}

$isi = file_get_contents($keluar);
printf(
    "Selesai: %s (%.1f KB, %d halaman)\n",
    basename($keluar),
    strlen($isi) / 1024,
    max(1, preg_match_all('#/Type\s*/Page[^s]#', $isi)),
);
