<?php

namespace App\Services\Ppsb;

use App\Exceptions\AppException;

/**
 * ATURAN DOMPET — wadi'ah (titipan, LIABILITAS). Perpindahan antar-dompet =
 * reklasifikasi antar-liabilitas (kas tak bergerak; jurnal D titipan asal /
 * K titipan tujuan, lihat DompetService::pindah).
 *
 * Sejak 13 Okt 2026 Santri→Wali SAH (keputusan pemilik): uang saku berlebih
 * boleh ditarik kembali wali. `kunci_tarik` sengaja TIDAK menghalanginya — gembok
 * itu khusus pencairan tunai.
 *
 * Tabungan→Wali hanya sah DENGAN PERSETUJUAN keuangan (pengajuan dari portal):
 * tabungan adalah simpanan, bukan uang saku, jadi tak boleh habis karena
 * keputusan sesaat. Pemindahan biasa tetap menolaknya.
 */
final class DompetPolicy
{
    private const ARAH_SAH = [
        ['dari' => 'wali', 'ke' => 'santri'],
        ['dari' => 'wali', 'ke' => 'tabungan'],
        ['dari' => 'santri', 'ke' => 'tabungan'],
        ['dari' => 'santri', 'ke' => 'wali'],
    ];

    /** Arah yang hanya sah lewat pengajuan yang sudah disetujui keuangan. */
    private const ARAH_DENGAN_PERSETUJUAN = [
        ['dari' => 'tabungan', 'ke' => 'wali'],
    ];

    private const LABEL = ['wali' => 'Dompet Wali', 'santri' => 'Dompet Santri', 'tabungan' => 'Tabungan Santri'];

    /** Akun COA titipan per dompet (semua LIABILITAS, saldo normal kredit). */
    public const COA_TITIPAN = [
        'wali' => '2.1.01.009',
        'santri' => '2.1.01.006',
        'tabungan' => '2.1.01.007',
    ];

    public static function labelDompet(string $p): string
    {
        return self::LABEL[$p] ?? $p;
    }

    public static function assertArahSah(string $dari, string $ke, bool $disetujui = false): void
    {
        if ($dari === $ke) {
            throw new AppException(422, __('Asal dan tujuan pemindahan tidak boleh sama.'));
        }
        $daftar = $disetujui ? [...self::ARAH_SAH, ...self::ARAH_DENGAN_PERSETUJUAN] : self::ARAH_SAH;
        $sah = false;
        foreach ($daftar as $a) {
            if ($a['dari'] === $dari && $a['ke'] === $ke) {
                $sah = true;
                break;
            }
        }
        if (! $sah) {
            if ($dari === 'tabungan') {
                throw new AppException(422, __('Tabungan Santri tidak bisa dipindah langsung. Wali mengajukan penarikan dari Portal Wali, lalu keuangan menyetujuinya; atau keuangan mencairkannya lewat Tarik / Kembalikan Titipan.'));
            }
            throw new AppException(422, __('Pemindahan dari :asal ke :tujuan tidak diizinkan.', [
                'asal' => __(self::labelDompet($dari)), 'tujuan' => __(self::labelDompet($ke)),
            ]));
        }
    }
}
