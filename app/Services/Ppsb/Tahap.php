<?php

namespace App\Services\Ppsb;

use App\Exceptions\AppException;

/**
 * MESIN TAHAP PENERIMAAN — satu-satunya penentu perpindahan status calon santri.
 * Peta murni (tanpa DB): melompati tahap = calon ikut tes tanpa membayar / diterima
 * tanpa diseleksi, tanpa gejala apa pun. Setiap perpindahan lewat sini.
 */
final class Tahap
{
    /** Status yang MENGAKHIRI proses. */
    public const STATUS_FINAL = ['tidak_lulus', 'gagal_medcheck', 'mengundurkan_diri', 'alumni', 'keluar'];

    /** Perpindahan yang sah: [status sekarang => tujuan yang diizinkan]. */
    public const TRANSISI = [
        'calon' => ['terbayar'],
        'terbayar' => ['terverifikasi'],
        'terverifikasi' => ['diseleksi'],
        'diseleksi' => ['diterima', 'tidak_lulus'],
        'diterima' => ['lolos_kesehatan', 'gagal_medcheck'],
        'lolos_kesehatan' => ['aktif'],
        'aktif' => ['alumni', 'keluar'],
    ];

    private const LABEL = [
        'calon' => 'Calon', 'terbayar' => 'Registrasi Terbayar', 'terverifikasi' => 'Berkas Terverifikasi',
        'diseleksi' => 'Sudah Diseleksi', 'diterima' => 'Diterima', 'lolos_kesehatan' => 'Lolos Kesehatan',
        'aktif' => 'Santri Aktif', 'alumni' => 'Alumni', 'tidak_lulus' => 'Tidak Lulus',
        'gagal_medcheck' => 'Gagal Med Check', 'mengundurkan_diri' => 'Mengundurkan Diri', 'keluar' => 'Keluar',
    ];

    public static function labelStatus(string $s): string
    {
        return self::LABEL[$s] ?? $s;
    }

    public static function isFinal(string $status): bool
    {
        return in_array($status, self::STATUS_FINAL, true);
    }

    public static function assertTransisi(string $dari, string $ke): void
    {
        if ($dari === $ke) {
            throw new AppException(422, 'Santri sudah berstatus "'.self::labelStatus($dari).'".');
        }
        if (self::isFinal($dari)) {
            throw new AppException(422, 'Proses santri ini sudah berakhir dengan status "'.self::labelStatus($dari).'" dan tidak bisa dilanjutkan.');
        }
        $boleh = self::TRANSISI[$dari] ?? [];
        if (! in_array($ke, $boleh, true)) {
            $daftar = implode(' atau ', array_map([self::class, 'labelStatus'], $boleh));
            throw new AppException(422, 'Dari "'.self::labelStatus($dari).'" tidak bisa langsung ke "'.self::labelStatus($ke).'"'
                .($daftar ? ". Tahap berikutnya yang sah: {$daftar}." : '.'));
        }
    }

    /** Pengunduran diri boleh kapan saja sebelum proses berakhir (tidak membalik jurnal). */
    public static function assertBolehMundur(string $dari): void
    {
        if (self::isFinal($dari)) {
            throw new AppException(422, 'Proses santri ini sudah berakhir dengan status "'.self::labelStatus($dari).'".');
        }
    }
}
