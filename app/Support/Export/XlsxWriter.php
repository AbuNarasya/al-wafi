<?php

namespace App\Support\Export;

use ZipArchive;

/**
 * Penulis XLSX minimal & mandiri (tanpa PhpSpreadsheet) — membangun paket OOXML
 * (zip berisi XML) memakai ekstensi zip PHP. Memakai inline string agar tidak
 * perlu sharedStrings; angka murni ditulis sebagai numerik, sisanya sebagai teks
 * (kode akun "1.1.001" & angka berawalan 0 tetap teks agar tidak rusak).
 */
class XlsxWriter
{
    /**
     * @param  array<int,string>  $header
     * @param  array<int,array<int,scalar|null>>  $rows
     * @return string  Biner file .xlsx
     */
    public static function build(array $header, array $rows): string
    {
        $sheet = self::sheetXml($header, $rows);

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        $zip->open($tmp, ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'</Types>');

        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');

        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Data" sheetId="1" r:id="rId1"/></sheets></workbook>');

        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'</Relationships>');

        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();

        $bin = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bin;
    }

    /**
     * @param  array<int,string>  $header
     * @param  array<int,array<int,scalar|null>>  $rows
     */
    private static function sheetXml(array $header, array $rows): string
    {
        $lines = [];
        $r = 1;
        if ($header) {
            $lines[] = self::rowXml($r++, $header, true);
        }
        foreach ($rows as $row) {
            $lines[] = self::rowXml($r++, array_values($row), false);
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>'.implode('', $lines).'</sheetData></worksheet>';
    }

    /** @param  array<int,scalar|null>  $cells */
    private static function rowXml(int $r, array $cells, bool $forceText): string
    {
        $out = "<row r=\"{$r}\">";
        $c = 0;
        foreach ($cells as $val) {
            $ref = self::colLetter($c++).$r;
            if (! $forceText && self::isNumber($val)) {
                $out .= "<c r=\"{$ref}\"><v>{$val}</v></c>";
            } else {
                $text = self::esc((string) ($val ?? ''));
                $out .= "<c r=\"{$ref}\" t=\"inlineStr\"><is><t xml:space=\"preserve\">{$text}</t></is></c>";
            }
        }

        return $out.'</row>';
    }

    private static function isNumber(mixed $v): bool
    {
        if (is_int($v) || is_float($v)) {
            return true;
        }
        if (! is_string($v) || $v === '') {
            return false;
        }
        if (! preg_match('/^-?\d+(\.\d+)?$/', $v)) {
            return false; // kode akun "1.1.001", tanggal, dll → teks
        }
        // Angka berawalan 0 (mis. "001") dipertahankan sebagai teks.
        $abs = ltrim($v, '-');

        return $abs === '0' || $abs[0] !== '0';
    }

    private static function colLetter(int $i): string
    {
        $s = '';
        $i++;
        while ($i > 0) {
            $m = ($i - 1) % 26;
            $s = chr(65 + $m).$s;
            $i = intdiv($i - 1, 26);
        }

        return $s;
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
