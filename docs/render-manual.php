<?php

/**
 * Render ulang manual book dari sumber HTML-nya menjadi PDF.
 *
 * Jalankan dari akar proyek:
 *     C:\php\php.exe docs/render-manual.php
 *
 * Sunting docs/manual-ppsb.html lebih dulu, lalu jalankan perintah di atas.
 * Memakai dompdf yang sudah menjadi dependensi proyek — tak perlu pasang apa pun.
 *
 * CATATAN saat menyunting CSS-nya: JANGAN memakai `float` di dalam elemen
 * `position: fixed` (footer). dompdf salah menghitung tinggi halaman dan jumlah
 * halaman membengkak lebih dari dua kali lipat. Pakai tabel dua sel.
 */
require __DIR__.'/../vendor/autoload.php';

$sumber = __DIR__.'/manual-ppsb.html';
$keluar = __DIR__.'/Manual-PPSB.pdf';

if (! is_file($sumber)) {
    fwrite(STDERR, "Sumber tidak ditemukan: {$sumber}\n");
    exit(1);
}

$options = new Dompdf\Options;
$options->set('isRemoteEnabled', false);
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf\Dompdf($options);
$dompdf->loadHtml(file_get_contents($sumber), 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

file_put_contents($keluar, $dompdf->output());

printf(
    "Selesai: %s (%.1f KB, %d halaman)\n",
    basename($keluar),
    filesize($keluar) / 1024,
    $dompdf->getCanvas()->get_page_count(),
);
