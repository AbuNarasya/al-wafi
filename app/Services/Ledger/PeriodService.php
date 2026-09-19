<?php

namespace App\Services\Ledger;

use App\Exceptions\AppException;
use App\Models\AccountingPeriod;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Kebijakan tutup buku: kapan sebuah tanggal boleh dijurnal.
 *
 * TUTUP BUKU MENGIKAT. Dulu di sini ada `GRACE_DAYS = 30`: periode yang SUDAH
 * ditutup tetap boleh dijurnal oleh siapa pun selama 30 hari sesudahnya.
 * Artinya laporan yang sudah dicetak dan disampaikan ke yayasan bisa berubah
 * angkanya setelah itu — tanpa jejak, tanpa pemberitahuan, dan tanpa seorang
 * pun perlu meminta izin. Toleransi itu dicabut.
 *
 * Periode tertutup kini menolak SEMUA jurnal. Membukanya kembali lewat
 * permohonan: admin keuangan mengajukan beserta alasannya, direktur keuangan
 * memutuskan — lihat [[App\Services\Modules\BukaPeriodeService]].
 */
final class PeriodService
{
    /** (tahun, bulan) dari sebuah tanggal jurnal. */
    public static function periodOf(string|CarbonInterface $tanggal): array
    {
        $c = $tanggal instanceof CarbonInterface ? $tanggal : Carbon::parse($tanggal);

        return ['tahun' => (int) $c->year, 'bulan' => (int) $c->month];
    }

    /**
     * Pastikan sebuah tanggal boleh dijurnal:
     *  - periode open / belum pernah ditutup → boleh.
     *  - periode closed → DITOLAK (422), tanpa kecuali dan tanpa toleransi hari.
     */
    public static function assertPeriodPostable(string|CarbonInterface $tanggal): void
    {
        ['tahun' => $tahun, 'bulan' => $bulan] = self::periodOf($tanggal);

        $period = AccountingPeriod::where('tahun', $tahun)->where('bulan', $bulan)->first();
        if (! $period || $period->status !== 'closed') {
            return;
        }

        $periode = str_pad((string) $bulan, 2, '0', STR_PAD_LEFT)."/{$tahun}";
        $ditutup = $period->closed_at ? ' (ditutup '.Carbon::parse($period->closed_at)->format('d/m/Y').')' : '';

        throw new AppException(422,
            "Periode {$periode} sudah ditutup{$ditutup}, jadi tak bisa dijurnal lagi. "
            .'Bila transaksi ini memang milik periode tersebut, ajukan pembukaan periode lewat menu '
            .'Tutup Buku Periode — permohonan diajukan admin keuangan dan diputuskan direktur keuangan.'
        );
    }
}
