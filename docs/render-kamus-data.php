<?php

/**
 * Susun KAMUS DATA (daftar tabel + kolom + penjelasannya) menjadi PDF.
 *
 * Jalankan dari akar proyek:
 *     C:\php\php.exe docs/render-kamus-data.php
 *
 * STRUKTURNYA dibaca LANGSUNG dari database yang sedang dipakai, bukan dari
 * catatan tangan — jadi dokumennya tak pernah bisa berbeda dari kenyataan.
 * PENJELASANNYA diambil dari docs/kamus-data-isi.php.
 *
 * Bila ada tabel di database yang belum dijelaskan (atau sebaliknya), skrip ini
 * BERHENTI dan menyebut namanya. Itu disengaja: kamus data yang diam-diam tidak
 * lengkap lebih berbahaya daripada tidak ada kamus sama sekali.
 *
 * Catatan CSS (mahal dipelajari saat merender manual book): JANGAN memakai
 * `float` di dalam elemen `position: fixed` — dompdf salah menghitung tinggi
 * halaman dan jumlah halamannya membengkak. Pakai tabel dua sel.
 */
// dompdf memuat SELURUH dokumen di memori sebelum menulis satu halaman pun.
// Dengan 1.100+ baris tabel, batas bawaan 128 MB habis di tengah jalan dan
// galatnya menyesatkan (tampak seperti kesalahan tata letak, padahal kehabisan
// memori). Dinaikkan di sini supaya perintah menjalankannya tetap sederhana.
ini_set('memory_limit', '1G');

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$isi = require __DIR__.'/kamus-data-isi.php';
$glosarium = $isi['glosarium'];

// ---------------------------------------------------------------- struktur
$namaTabel = collect(DB::select("select tablename from pg_tables where schemaname='public'"))
    ->pluck('tablename')->sort()->values()->all();

$kolomSemua = DB::select("
    select table_name, column_name, data_type, character_maximum_length,
           numeric_precision, numeric_scale, is_nullable, ordinal_position
    from information_schema.columns where table_schema='public'
    order by table_name, ordinal_position
");
$pkRows = DB::select("
    select tc.table_name, kcu.column_name
    from information_schema.table_constraints tc
    join information_schema.key_column_usage kcu on kcu.constraint_name = tc.constraint_name
    where tc.constraint_type='PRIMARY KEY' and tc.table_schema='public'
");
$fkRows = DB::select("
    select tc.table_name, kcu.column_name, ccu.table_name as ref_table
    from information_schema.table_constraints tc
    join information_schema.key_column_usage kcu on kcu.constraint_name = tc.constraint_name
    join information_schema.constraint_column_usage ccu on ccu.constraint_name = tc.constraint_name
    where tc.constraint_type='FOREIGN KEY' and tc.table_schema='public'
");

$pk = [];
foreach ($pkRows as $r) {
    $pk[$r->table_name][] = $r->column_name;
}
$fk = [];
foreach ($fkRows as $r) {
    $fk[$r->table_name][$r->column_name] = $r->ref_table;
}

$kolom = [];
foreach ($kolomSemua as $c) {
    $tipe = match ($c->data_type) {
        'character varying' => 'varchar'.($c->character_maximum_length ? "({$c->character_maximum_length})" : ''),
        'timestamp without time zone' => 'timestamp',
        'timestamp with time zone' => 'timestamptz',
        'numeric' => "numeric({$c->numeric_precision},{$c->numeric_scale})",
        'integer' => 'integer',
        'bigint' => 'bigint',
        'boolean' => 'boolean',
        'text' => 'text',
        'date' => 'date',
        default => $c->data_type,
    };
    $kolom[$c->table_name][] = [
        'nama' => $c->column_name,
        'tipe' => $tipe,
        'null' => $c->is_nullable === 'YES',
    ];
}

// ------------------------------------------------- pemeriksaan kelengkapan
$dijelaskan = [];
foreach ($isi['bagian'] as $b) {
    foreach (array_keys($b['tabel']) as $t) {
        $dijelaskan[] = $t;
    }
}
$kurang = array_diff($namaTabel, $dijelaskan);
$lebih = array_diff($dijelaskan, $namaTabel);
$kembar = array_diff_assoc($dijelaskan, array_unique($dijelaskan));

if ($kurang || $lebih || $kembar) {
    fwrite(STDERR, "KAMUS TIDAK LENGKAP — dokumen tidak dibuat.\n");
    if ($kurang) {
        fwrite(STDERR, '  Ada di database, belum dijelaskan: '.implode(', ', $kurang)."\n");
    }
    if ($lebih) {
        fwrite(STDERR, '  Dijelaskan, tapi tak ada di database: '.implode(', ', $lebih)."\n");
    }
    if ($kembar) {
        fwrite(STDERR, '  Dijelaskan lebih dari sekali: '.implode(', ', $kembar)."\n");
    }
    exit(1);
}

// ------------------------------------------------------------------ render
$e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$tanggal = date('j F Y');
$bulanId = [
    'January' => 'Januari', 'February' => 'Februari', 'March' => 'Maret', 'April' => 'April',
    'May' => 'Mei', 'June' => 'Juni', 'July' => 'Juli', 'August' => 'Agustus',
    'September' => 'September', 'October' => 'Oktober', 'November' => 'November', 'December' => 'Desember',
];
$tanggal = strtr($tanggal, $bulanId);

$totalKolom = array_sum(array_map('count', $kolom));

ob_start(); ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 22mm 16mm 20mm 16mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.6pt; line-height: 1.45; color: #1f2937; }

    /* Footer: tabel dua sel, BUKAN float (dompdf salah hitung halaman). */
    .kaki { position: fixed; bottom: -12mm; left: 0; right: 0; font-size: 7pt; color: #9ca3af; }
    .kaki table { width: 100%; border: 0; }
    .kaki td { border: 0; padding: 0; }
    .kaki .kanan { text-align: right; }

    h1 { font-size: 22pt; margin: 0 0 4pt; color: #14532d; }
    h2 { font-size: 13pt; margin: 0 0 6pt; padding-bottom: 4pt; border-bottom: 2px solid #14532d; color: #14532d; page-break-after: avoid; }
    h3 { font-size: 10pt; margin: 12pt 0 3pt; color: #111827; page-break-after: avoid; }
    p { margin: 0 0 5pt; text-align: justify; }

    .sampul { text-align: center; padding-top: 55mm; }
    .sampul .sub { font-size: 11pt; color: #4b5563; margin-top: 4pt; }
    .sampul .meta { margin-top: 26mm; font-size: 9pt; color: #6b7280; }

    .ringkas { background: #f0fdf4; border-left: 3px solid #16a34a; padding: 6pt 8pt; margin-bottom: 8pt; }
    .catatan { background: #fffbeb; border-left: 3px solid #f59e0b; padding: 5pt 7pt; margin: 4pt 0 6pt; font-size: 8.2pt; }
    .catatan b { color: #92400e; }

    table.kolom { width: 100%; border-collapse: collapse; margin: 4pt 0 2pt; }
    table.kolom th { background: #f3f4f6; text-align: left; font-size: 7.4pt; text-transform: uppercase;
                     letter-spacing: .3pt; color: #4b5563; padding: 3pt 4pt; border-bottom: 1px solid #d1d5db; }
    table.kolom td { padding: 2.5pt 4pt; border-bottom: 1px solid #f3f4f6; vertical-align: top; font-size: 8pt; }
    table.kolom td.k { font-family: 'DejaVu Sans Mono', monospace; font-size: 7.4pt; color: #111827; }
    table.kolom td.t { font-family: 'DejaVu Sans Mono', monospace; font-size: 7pt; color: #6b7280; white-space: nowrap; }
    table.kolom td.n { text-align: center; color: #9ca3af; font-size: 7pt; }
    .pk { background: #dcfce7; color: #14532d; font-size: 6.4pt; padding: 0 2pt; border-radius: 2pt; }
    .fk { color: #1d4ed8; font-size: 6.8pt; }

    .judul-tabel { font-family: 'DejaVu Sans Mono', monospace; font-size: 8pt; color: #6b7280; font-weight: normal; }
    .jml { font-size: 7.2pt; color: #9ca3af; font-weight: normal; }

    table.daftar { width: 100%; border-collapse: collapse; font-size: 8pt; }
    table.daftar td { padding: 2pt 4pt; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
    table.daftar td.b { font-family: 'DejaVu Sans Mono', monospace; font-size: 7.4pt; width: 42%; }
    .baru { page-break-before: always; }
</style>
</head>
<body>

<div class="kaki">
    <table><tr>
        <td>Kamus Data — Al Wafi ERP</td>
        <td class="kanan">Halaman <span class="pagenum"></span></td>
    </tr></table>
</div>

<div class="sampul">
    <h1>Kamus Data</h1>
    <div class="sub">Al Wafi ERP — Aplikasi Keuangan &amp; Kesantrian Pesantren</div>
    <div class="meta">
        <?= count($namaTabel) ?> tabel &middot; <?= $totalKolom ?> kolom<br>
        Disusun <?= $e($tanggal) ?><br><br>
        Struktur dibaca langsung dari database <b><?= $e(config('database.connections.pgsql.database')) ?></b>,<br>
        sehingga isinya tak pernah berbeda dari keadaan sebenarnya.
    </div>
</div>

<div class="baru">
<h2>Cara Membaca Dokumen Ini</h2>
<p>Dokumen ini menjelaskan seluruh tabel database aplikasi, dikelompokkan menurut
fungsinya — bukan menurut abjad — supaya tabel yang saling berhubungan terbaca
berdekatan. Tiap tabel diawali penjelasan untuk apa ia ada, lalu daftar kolomnya.</p>

<p>Pada daftar kolom: <span class="pk">PK</span> menandai kunci utama, dan panah
<span class="fk">&rarr; tabel</span> menandai kunci asing beserta tabel yang
dirujuknya. Kolom <b>Null</b> bertanda &check; berarti isian itu boleh
dikosongkan.</p>

<div class="catatan">
    <b>Yang perlu diingat sebelum membaca.</b> Sebagian besar tabel master di
    aplikasi ini TIDAK memakai <span class="judul-tabel">id</span> berurut sebagai
    kunci utama, melainkan kode berupa teks — <span class="judul-tabel">kode</span>,
    <span class="judul-tabel">kode_coa</span>, <span class="judul-tabel">kode_unit</span>,
    dan seterusnya. Pengecualian yang paling sering menjebak adalah
    <span class="judul-tabel">tahun_ajaran</span>, yang justru berkunci utama
    <span class="judul-tabel">id</span> berupa angka, sehingga mencarinya harus
    lewat kolom <span class="judul-tabel">kode</span>.
</div>

<h3>Daftar Bagian</h3>
<table class="daftar">
<?php foreach ($isi['bagian'] as $i => $b) { ?>
    <tr>
        <td style="width:6%"><?= $i + 1 ?>.</td>
        <td><b><?= $e($b['judul']) ?></b></td>
        <td style="width:14%; text-align:right; color:#9ca3af"><?= count($b['tabel']) ?> tabel</td>
    </tr>
<?php } ?>
</table>
</div>

<?php foreach ($isi['bagian'] as $i => $b) { ?>
<div class="baru">
    <h2><?= $i + 1 ?>. <?= $e($b['judul']) ?></h2>
    <div class="ringkas"><?= $e($b['ringkas']) ?></div>
    <?php if (! empty($b['catatan'])) { ?>
        <div class="catatan"><?= $e($b['catatan']) ?></div>
    <?php } ?>

    <?php foreach ($b['tabel'] as $nama => $t) { ?>
        <h3><?= $e($t['judul']) ?> &nbsp;<span class="judul-tabel"><?= $e($nama) ?></span>
            <span class="jml">(<?= count($kolom[$nama]) ?> kolom)</span></h3>
        <p><?= $e($t['fungsi']) ?></p>
        <?php if (! empty($t['catatan'])) { ?>
            <div class="catatan"><b>Perhatian.</b> <?= $e($t['catatan']) ?></div>
        <?php } ?>

        <table class="kolom">
            <thead><tr>
                <th style="width:26%">Kolom</th>
                <th style="width:16%">Tipe</th>
                <th style="width:6%">Null</th>
                <th>Keterangan</th>
            </tr></thead>
            <tbody>
            <?php foreach ($kolom[$nama] as $k) {
                $ket = $t['kolom'][$k['nama']] ?? $glosarium[$k['nama']] ?? '';
                $isPk = in_array($k['nama'], $pk[$nama] ?? [], true);
                $ref = $fk[$nama][$k['nama']] ?? null;
                ?>
                <tr>
                    <td class="k">
                        <?= $e($k['nama']) ?>
                        <?php if ($isPk) { ?><span class="pk">PK</span><?php } ?>
                        <?php if ($ref) { ?><br><span class="fk">&rarr; <?= $e($ref) ?></span><?php } ?>
                    </td>
                    <td class="t"><?= $e($k['tipe']) ?></td>
                    <td class="n"><?= $k['null'] ? '&check;' : '' ?></td>
                    <td><?= $e($ket) ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    <?php } ?>
</div>
<?php } ?>

</body>
</html>
<?php
$html = ob_get_clean();

// Versi HTML disimpan juga: enak diperiksa di peramban sebelum PDF-nya dipakai,
// dan bisa dibuka tanpa aplikasi pembaca PDF. Bukan sumber — keduanya lahir dari
// skrip yang sama.
file_put_contents(__DIR__.'/kamus-data.html', $html);

$options = new Dompdf\Options;
$options->set('isRemoteEnabled', false);
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf\Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Nomor halaman ditulis sesudah render, saat jumlah halamannya sudah pasti.
$dompdf->getCanvas()->page_text(
    520, 802, '{PAGE_NUM} / {PAGE_COUNT}', null, 7, [0.61, 0.64, 0.69]
);

$keluar = __DIR__.'/Kamus-Data-Al-Wafi.pdf';
file_put_contents($keluar, $dompdf->output());

printf(
    "Selesai: %s (%.1f KB, %d halaman, %d tabel, %d kolom)\n",
    basename($keluar),
    filesize($keluar) / 1024,
    $dompdf->getCanvas()->get_page_count(),
    count($namaTabel),
    $totalKolom,
);
