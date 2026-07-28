<?php

namespace App\Support\Export;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Mesin export terpusat (port perilaku exportRows dev): terima baris asosiatif
 * [label => nilai] dan hasilkan CSV / XLSX / PDF. Header kolom = kunci baris
 * pertama. Dipakai semua halaman (Export Data, Laporan, Kontrol, Dashboard).
 */
class Exporter
{
    public const FORMATS = ['csv', 'xlsx', 'pdf'];

    /**
     * @param  array<int,array<string,scalar|null>>  $rows
     */
    public static function download(string $format, string $baseName, string $title, array $rows): Response|StreamedResponse
    {
        $format = in_array($format, self::FORMATS, true) ? $format : 'csv';
        $header = $rows ? array_keys($rows[0]) : [];
        $file = $baseName.'_'.now()->format('Ymd_His');

        return match ($format) {
            'xlsx' => self::xlsx($file, $header, $rows),
            'pdf' => self::pdf($file, $title, $header, $rows),
            default => self::csv($file, $header, $rows),
        };
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
