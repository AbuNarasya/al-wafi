<?php

namespace App\Services\Ledger;

use App\Models\JournalEntry;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Penomoran dokumen & referensi jurnal berurutan per bulan:
 *   <PREFIX>-<YYMM>-<NNNN>   contoh: KM-2607-0001
 */
final class DocNumber
{
    /** Basis nomor untuk sebuah prefix + tanggal, mis. "KM-2607-". */
    public static function docBase(string $prefix, string|CarbonInterface $tanggal): string
    {
        $c = $tanggal instanceof CarbonInterface ? $tanggal : Carbon::parse($tanggal);
        $yy = substr((string) $c->year, 2);
        $mm = str_pad((string) $c->month, 2, '0', STR_PAD_LEFT);

        return "{$prefix}-{$yy}{$mm}-";
    }

    /** Nomor berikutnya dari nomor terakhir pada basis yang sama. */
    public static function nextDocNumber(string $base, ?string $lastNomor): string
    {
        $seq = 1;
        if ($lastNomor !== null) {
            $tail = substr($lastNomor, strlen($base));
            if (is_numeric($tail)) {
                $seq = ((int) $tail) + 1;
            }
        }

        return $base.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    /** Referensi jurnal berikutnya (berbasis referensi terakhir bulan yang sama). */
    public static function nextJournalRef(string $prefix, string|CarbonInterface $tanggal): string
    {
        $base = self::docBase($prefix, $tanggal);
        $last = JournalEntry::where('referensi', 'like', $base.'%')
            ->orderByDesc('referensi')
            ->value('referensi');

        return self::nextDocNumber($base, $last);
    }
}
