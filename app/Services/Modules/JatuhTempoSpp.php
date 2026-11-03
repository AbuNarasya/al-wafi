<?php

namespace App\Services\Modules;

use App\Models\ReminderSetting;
use App\Models\TagihanSantri;
use Illuminate\Support\Carbon;

/**
 * JATUH TEMPO SPP BAWAAN (keputusan user 3 Nov 2026) — tanggal 1–31 di pengaturan Reminder Tagihan.
 * SPP periode "2026-09" dengan bawaan 10 → jatuh tempo 10/09/2026. Bulan yang lebih pendek memakai hari
 * terakhirnya (31 → 28/29 Februari). Hanya MENGISI yang kosong: jatuh tempo yang diketik tak pernah ditimpa.
 */
class JatuhTempoSpp
{
    /** Tanggal bawaan dari pengaturan; null = tak diatur. */
    public static function tanggal(): ?int
    {
        $t = ReminderSetting::query()->value('tanggal_jatuh_tempo_spp');

        return $t ? (int) $t : null;
    }

    /** Jatuh tempo untuk satu periode "YYYY-MM"; null bila periodenya tak berbentuk bulan atau tak ada bawaan. */
    public static function untukPeriode(?string $periode, ?int $tanggal = null): ?string
    {
        $tanggal ??= self::tanggal();
        if (! $tanggal || ! preg_match('/^(\d{4})-(\d{2})$/', (string) $periode, $m)) {
            return null;
        }
        $awal = Carbon::create((int) $m[1], (int) $m[2], 1);

        return $awal->day(min($tanggal, $awal->daysInMonth))->toDateString();
    }

    /** Isi jatuh tempo SPP belum lunas yang masih kosong. @return int jumlah tagihan yang diisi */
    public static function isiYangKosong(int $tanggal): int
    {
        $n = 0;
        TagihanSantri::query()->where('perilaku', 'spp')->whereNull('jatuh_tempo')
            ->whereIn('status', ['belum_bayar', 'sebagian'])
            ->select('periode')->distinct()->pluck('periode')
            ->each(function ($periode) use ($tanggal, &$n) {
                if ($jt = self::untukPeriode($periode, $tanggal)) {
                    $n += TagihanSantri::where('perilaku', 'spp')->whereNull('jatuh_tempo')
                        ->whereIn('status', ['belum_bayar', 'sebagian'])->where('periode', $periode)
                        ->update(['jatuh_tempo' => $jt]);
                }
            });

        return $n;
    }
}
