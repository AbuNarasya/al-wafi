<?php

namespace App\Services\Ledger;

use App\Models\Budget;
use App\Models\JournalLine;
use App\Models\PengajuanPembayaranDetail;
use App\Support\Money;

/**
 * Kebijakan PEMAKAIAN ANGGARAN — dasar eskalasi overbudget (§4.c).
 * Diukur kumulatif YTD per (akun × bagian): anggaran vs (realisasi + KOMITMEN).
 * Komitmen = pengajuan "diajukan" yang belum berjurnal — wajib ikut agar dua
 * pengajuan @60% tidak lolos senyap jadi 120%.
 */
final class AnggaranPolicy
{
    /**
     * @param  array{tahun:int,bulan:int,kode_coa:string,kode_bagian:string,abaikanPengajuanId?:int|null}  $p
     * @return array{anggaran:string,realisasi:string,komitmen:string,terpakai:string,sisa:string,belum_dianggarkan:bool}
     */
    public static function hitungPemakaian(array $p): array
    {
        $tahun = $p['tahun'];
        $bulan = $p['bulan'];
        $kodeCoa = $p['kode_coa'];
        $kodeBagian = $p['kode_bagian'];
        $abaikan = $p['abaikanPengajuanId'] ?? null;

        $bulanAwal = AnggaranPeriode::bulanAwalAnggaran();
        ['awal' => $awal, 'akhir' => $akhir, 'pairs' => $pairs] = AnggaranPeriode::rentangYtdAnggaran($tahun, $bulan, $bulanAwal);

        // 1. Anggaran kumulatif awal-TA..bulan (lintas unit, per akun × bagian).
        $anggaran = Budget::where('kode_coa', $kodeCoa)
            ->where('kode_bagian', $kodeBagian)
            ->where(function ($q) use ($pairs) {
                foreach ($pairs as $pp) {
                    $q->orWhere(fn ($qq) => $qq->where('tahun', $pp['tahun'])->where('bulan', $pp['bulan']));
                }
            })
            ->sum('nominal');

        // 2. Realisasi dari jurnal (debet − kredit), jurnal non-kas dikecualikan.
        $realisasiQuery = fn () => JournalLine::where('kode_coa', $kodeCoa)
            ->where('kode_bagian', $kodeBagian)
            ->whereHas('entry', fn ($q) => $q
                ->whereBetween('tanggal', [$awal->toDateString(), $akhir->toDateString()])
                ->whereNotIn('sumber_modul', BagianPolicy::SUMBER_NON_KAS));
        $realisasi = Money::sub($realisasiQuery()->sum('debet'), $realisasiQuery()->sum('kredit'));

        // 3. Komitmen: baris pengajuan "diajukan" yang belum berjurnal.
        $komitmen = PengajuanPembayaranDetail::where('kode_coa', $kodeCoa)
            ->whereHas('pengajuan', function ($q) use ($kodeBagian, $awal, $akhir, $abaikan) {
                $q->where('kode_bagian', $kodeBagian)
                    ->where('status', 'diajukan')
                    ->whereBetween('tanggal', [$awal->toDateString(), $akhir->toDateString()]);
                if ($abaikan) {
                    $q->where('id', '!=', $abaikan);
                }
            })
            ->sum('nominal');

        $anggaran = Money::of($anggaran);
        $terpakai = Money::add($realisasi, $komitmen);

        return [
            'anggaran' => $anggaran,
            'realisasi' => Money::of($realisasi),
            'komitmen' => Money::of($komitmen),
            'terpakai' => $terpakai,
            'sisa' => Money::sub($anggaran, $terpakai),
            'belum_dianggarkan' => Money::isZero($anggaran),
        ];
    }

    /**
     * Menilai apakah pengajuan menembus anggaran → tahap eskalasi diaktifkan.
     * Membandingkan PROYEKSI (terpakai + nominal diajukan), bukan pemakaian saat ini.
     *
     * @param  array{tahun:int,bulan:int,kode_coa:string,kode_bagian:string,nominal:string|int|float,abaikanPengajuanId?:int|null}  $p
     * @return array{anggaran:string,realisasi:string,komitmen:string,terpakai:string,sisa:string,belum_dianggarkan:bool,diauskan?:string,diajukan:string,proyeksi:string,overbudget:bool,perlu_eskalasi:bool}
     */
    public static function evaluasiAnggaran(array $p): array
    {
        $pakai = self::hitungPemakaian($p);
        $diajukan = Money::of($p['nominal']);
        $proyeksi = Money::add($pakai['terpakai'], $diajukan);

        $belum = $pakai['belum_dianggarkan'];
        $overbudget = ! $belum && Money::gt($proyeksi, $pakai['anggaran']);

        return array_merge($pakai, [
            'diajukan' => $diajukan,
            'proyeksi' => $proyeksi,
            'overbudget' => $overbudget,
            'perlu_eskalasi' => $overbudget || $belum,
        ]);
    }
}
