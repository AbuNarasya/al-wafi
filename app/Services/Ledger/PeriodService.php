<?php

namespace App\Services\Ledger;

use App\Exceptions\AppException;
use App\Models\AccountingPeriod;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Kebijakan tutup buku: kapan sebuah tanggal boleh dijurnal.
 */
final class PeriodService
{
    /** Toleransi backdate ke periode tertutup: 30 hari sejak closed_at. */
    public const GRACE_DAYS = 30;

    /** (tahun, bulan) dari sebuah tanggal jurnal. */
    public static function periodOf(string|CarbonInterface $tanggal): array
    {
        $c = $tanggal instanceof CarbonInterface ? $tanggal : Carbon::parse($tanggal);

        return ['tahun' => (int) $c->year, 'bulan' => (int) $c->month];
    }

    /**
     * Pastikan sebuah tanggal boleh dijurnal:
     *  - periode open / belum pernah ditutup → boleh.
     *  - periode closed tapi ≤ 30 hari sejak closed_at → boleh (toleransi backdate).
     *  - periode closed & > 30 hari sejak closed_at → DITOLAK (422).
     */
    public static function assertPeriodPostable(string|CarbonInterface $tanggal): void
    {
        ['tahun' => $tahun, 'bulan' => $bulan] = self::periodOf($tanggal);

        $period = AccountingPeriod::where('tahun', $tahun)->where('bulan', $bulan)->first();
        if (! $period || $period->status !== 'closed') {
            return;
        }

        $closedAt = $period->closed_at?->getTimestamp() ?? 0;
        $lewatHari = (Carbon::now()->getTimestamp() - $closedAt) / 86400;

        if ($lewatHari > self::GRACE_DAYS) {
            throw new AppException(
                422,
                'Periode '.str_pad((string) $bulan, 2, '0', STR_PAD_LEFT)."/{$tahun} sudah ditutup lebih dari "
                .self::GRACE_DAYS.' hari (backdate tidak diizinkan). Minta pembukaan periode ke level tertinggi.'
            );
        }
    }
}
