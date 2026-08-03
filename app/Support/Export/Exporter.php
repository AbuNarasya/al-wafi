<?php

namespace App\Support\Export;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Mesin export terpusat (port perilaku exportRows dev): terima baris asosiatif
 * [label => nilai] dan hasilkan CSV / XLSX / PDF. Header kolom = kunci baris
 * pertama. Dipakai semua halaman (Export Data, Laporan, Kontrol, Dashboard).
 *
 * PEMILIHAN KOLOM ditangani DI SINI, bukan di tiap pemanggil. Seluruh unduhan
 * di aplikasi ini berakhir di download(), jadi menaruhnya di sini membuat
 * fasilitasnya berlaku serentak — termasuk untuk unduhan yang ditambahkan
 * nanti, yang kalau tidak begitu pasti terlupa. Dua parameter `kolom`:
 *
 *   ?kolom=daftar   → JSON berisi NAMA KOLOM yang tersedia (tanpa datanya),
 *                     dipakai panel "Kolom" untuk mengisi centangnya.
 *   ?kolom[]=A&…    → berkas hanya berisi kolom itu.
 *   (tanpa kolom)   → seluruh kolom, seperti sebelum fasilitas ini ada.
 */
class Exporter
{
    public const FORMATS = ['csv', 'xlsx', 'pdf'];

    /** Nama parameter kueri & nilai khusus "sebutkan kolomnya saja". */
    public const PARAM_KOLOM = 'kolom';

    public const MINTA_DAFTAR = 'daftar';

    /**
     * @param  array<int,array<string,scalar|null>>  $rows
     * @param  array<int,string>|string|null  $kolom  kolom yang diminta; null = baca dari query
     */
    public static function download(string $format, string $baseName, string $title, array $rows, array|string|null $kolom = null): Response|StreamedResponse|JsonResponse
    {
        $minta = $kolom ?? request()->query(self::PARAM_KOLOM);

        // Panel pemilih bertanya "kolom apa saja yang ada?" ke ALAMAT UNDUHAN
        // YANG SAMA. Sengaja begitu, bukan ke daftar kolom yang ditulis
        // terpisah: daftar terpisah pasti menyimpang begitu satu pembangun
        // baris diubah, dan menyimpangnya tanpa gejala apa pun. Harganya:
        // barisnya memang dibangun untuk pertanyaan ini — hanya saat panelnya
        // DIBUKA, sekali per target.
        if ($minta === self::MINTA_DAFTAR) {
            return response()->json(['kolom' => $rows ? array_keys($rows[0]) : []]);
        }

        $rows = self::saring($rows, is_array($minta) ? $minta : null);

        $format = in_array($format, self::FORMATS, true) ? $format : 'csv';
        $header = $rows ? array_keys($rows[0]) : [];
        $file = $baseName.'_'.now()->format('Ymd_His');

        return match ($format) {
            'xlsx' => self::xlsx($file, $header, $rows),
            'pdf' => self::pdf($file, $title, $header, $rows),
            default => self::csv($file, $header, $rows),
        };
    }

    /**
     * Ambil kolom yang diminta saja.
     *
     * Urutannya mengikuti SUMBER, bukan urutan centang — berkas yang susunan
     * kolomnya berpindah-pindah tiap kali diunduh tak bisa ditempelkan ke
     * lembar kerja yang sama. Kolom yang diminta tapi tak dikenal diabaikan;
     * bila TAK SATU PUN cocok (mis. tautan lama setelah kolomnya berganti
     * nama), seluruh kolom yang diterbitkan — berkas kosong tanpa sebab yang
     * terlihat jauh lebih menyesatkan daripada berkas yang kelebihan kolom.
     *
     * @param  array<int,array<string,scalar|null>>  $rows
     * @param  array<int,string>|null  $kolom
     * @return array<int,array<string,scalar|null>>
     */
    private static function saring(array $rows, ?array $kolom): array
    {
        if (! $rows || ! $kolom) {
            return $rows;
        }

        $pilih = array_values(array_intersect(array_keys($rows[0]), $kolom));
        if (! $pilih) {
            return $rows;
        }

        // array_replace atas kerangka kosong: baris yang kebetulan tak punya
        // salah satu kunci tetap sejajar dengan headernya (CSV & XLSX menulis
        // array_values, jadi kunci yang hilang akan menggeser seluruh sel).
        $kerangka = array_fill_keys($pilih, '');

        return array_map(fn ($r) => array_replace($kerangka, array_intersect_key($r, $kerangka)), $rows);
    }

    /** @param array<int,string> $header @param array<int,array<string,scalar|null>> $rows */
    private static function csv(string $file, array $header, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            if ($header) {
                fputcsv($out, $header);
            }
            foreach ($rows as $r) {
                fputcsv($out, array_values($r));
            }
            fclose($out);
        }, $file.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @param array<int,string> $header @param array<int,array<string,scalar|null>> $rows */
    private static function xlsx(string $file, array $header, array $rows): Response
    {
        $bin = XlsxWriter::build($header, array_map('array_values', $rows));

        return new Response($bin, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$file.'.xlsx"',
        ]);
    }

    /** @param array<int,string> $header @param array<int,array<string,scalar|null>> $rows */
    private static function pdf(string $file, string $title, array $header, array $rows): Response
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(self::pdfHtml($title, $header, $rows), 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return new Response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$file.'.pdf"',
        ]);
    }

    /** @param array<int,string> $header @param array<int,array<string,scalar|null>> $rows */
    private static function pdfHtml(string $title, array $header, array $rows): string
    {
        $esc = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $th = '';
        foreach ($header as $h) {
            $th .= '<th>'.$esc($h).'</th>';
        }
        $tb = '';
        foreach ($rows as $r) {
            $tb .= '<tr>';
            foreach ($r as $v) {
                $num = is_numeric($v) && ! (is_string($v) && strlen($v) > 1 && $v[0] === '0');
                $tb .= '<td class="'.($num ? 'num' : '').'">'.$esc($v).'</td>';
            }
            $tb .= '</tr>';
        }
        if (! $rows) {
            $tb = '<tr><td colspan="'.max(1, count($header)).'" style="text-align:center;color:#999">Tidak ada data.</td></tr>';
        }
        $tgl = now()->format('d/m/Y H:i');

        return '<!doctype html><html><head><meta charset="utf-8"><style>'
            .'*{font-family:"DejaVu Sans",sans-serif}'
            .'body{font-size:9px;color:#222}'
            .'h1{font-size:14px;margin:0 0 2px}'
            .'.meta{font-size:8px;color:#777;margin-bottom:8px}'
            .'table{width:100%;border-collapse:collapse}'
            .'th,td{border:1px solid #ccc;padding:3px 5px;text-align:left;vertical-align:top}'
            .'th{background:#f1f5f9;text-transform:uppercase;font-size:8px}'
            .'td.num{text-align:right}'
            .'</style></head><body>'
            .'<h1>'.$esc($title).'</h1><div class="meta">Dicetak: '.$tgl.' &middot; '.count($rows).' baris</div>'
            .'<table><thead><tr>'.$th.'</tr></thead><tbody>'.$tb.'</tbody></table>'
            .'</body></html>';
    }
}
