<?php

namespace App\Services\Ledger;

use App\Models\CompanySettings;
use Illuminate\Support\Carbon;

/**
 * Periode Tahun Anggaran (TA) fleksibel — CAKUPAN: ANGGARAN SAJA.
 * Penyimpanan anggaran tetap kalender (Budget.tahun/bulan); TA hanya lapisan
 * pemetaan. Label TA = tahun kalender bulan pertamanya. Fungsi murni (kecuali
 * bulanAwalAnggaran yang membaca setelan).
 */
final class AnggaranPeriode
{
    public const BULAN_AWAL_DEFAULT = 1;

    /** Jaga nilai ke 1..12; di luar itu → default (Januari). */
    public static function normalisasiBulanAwal(?int $b): int
    {
        return ($b !== null && $b >= 1 && $b <= 12) ? $b : self::BULAN_AWAL_DEFAULT;
    }

    /**
     * 12 pasang (tahun, bulan) kalender berurutan sejak bulanAwal tahun TA.
     * @return array<int,array{tahun:int,bulan:int}>
     */
    public static function bulanTahunAnggaran(int $tahunAnggaran, int $bulanAwal): array
    {
        $b0 = self::normalisasiBulanAwal($bulanAwal);
        $out = [];
        for ($i = 0; $i < 12; $i++) {
            $geser = $b0 - 1 + $i;
            $out[] = ['tahun' => $tahunAnggaran + intdiv($geser, 12), 'bulan' => ($geser % 12) + 1];
        }

        return $out;
    }

    /**
     * Indeks SLOT 0..11 dari (tahun, bulan) kalender di dalam TA berlabel
     * $tahunAnggaran, atau null bila di luar 12 bulan TA itu.
     */
    public static function slotDalamTA(int $tahunAnggaran, int $bulanAwal, int $tahun, int $bulan): ?int
    {
        foreach (self::bulanTahunAnggaran($tahunAnggaran, $bulanAwal) as $i => $p) {
            if ($p['tahun'] === $tahun && $p['bulan'] === $bulan) {
                return $i;
            }
        }

        return null;
    }

    /** TA yang MEMUAT satu (tahun, bulan) kalender. */
    public static function tahunAnggaranDari(int $tahun, int $bulan, int $bulanAwal): int
    {
        $b0 = self::normalisasiBulanAwal($bulanAwal);

        return $bulan >= $b0 ? $tahun : $tahun - 1;
    }

    /** Tanggal awal TA (hari-1 bulan pertama), UTC. */
    public static function awalTahunAnggaran(int $tahunAnggaran, int $bulanAwal): Carbon
    {
        $b0 = self::normalisasiBulanAwal($bulanAwal);

        return Carbon::create($tahunAnggaran, $b0, 1, 0, 0, 0, 'UTC');
    }

    /** Akhir bulan (hari terakhir `bulan` 23:59:59), UTC. */
    public static function akhirBulan(int $tahun, int $bulan): Carbon
    {
        return Carbon::create($tahun, $bulan, 1, 0, 0, 0, 'UTC')->endOfMonth();
    }

    /** Label tampilan TA: "2026" bila mulai Januari, selainnya "2026/2027". */
    public static function labelTahunAnggaran(int $tahunAnggaran, int $bulanAwal): string
    {
        return self::normalisasiBulanAwal($bulanAwal) === 1
            ? (string) $tahunAnggaran
            : "{$tahunAnggaran}/".($tahunAnggaran + 1);
    }

    /**
     * Rentang kumulatif YTD-fiscal untuk (tahun, bulan): awal TA yang memuatnya
     * s/d akhir bulan itu, plus pasangan (tahun,bulan) kalender di rentang tsb.
     * @return array{tahunAnggaran:int,awal:Carbon,akhir:Carbon,pairs:array<int,array{tahun:int,bulan:int}>}
     */
    public static function rentangYtdAnggaran(int $tahun, int $bulan, int $bulanAwal): array
    {
        $ta = self::tahunAnggaranDari($tahun, $bulan, $bulanAwal);
        $semua = self::bulanTahunAnggaran($ta, $bulanAwal);
        $idx = -1;
        foreach ($semua as $i => $p) {
            if ($p['tahun'] === $tahun && $p['bulan'] === $bulan) {
                $idx = $i;
                break;
            }
        }

        return [
            'tahunAnggaran' => $ta,
            'awal' => self::awalTahunAnggaran($ta, $bulanAwal),
            'akhir' => self::akhirBulan($tahun, $bulan),
            'pairs' => $idx === -1 ? $semua : array_slice($semua, 0, $idx + 1),
        ];
    }

    /** Baca bulan awal TA dari setelan global (default 1 bila belum diset). */
    public static function bulanAwalAnggaran(): int
    {
        $s = CompanySettings::query()->value('bulan_awal_anggaran');

        return self::normalisasiBulanAwal($s !== null ? (int) $s : null);
    }
}
