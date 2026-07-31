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

    /**
     * Status yang TIDAK BOLEH lagi muncul di pemilih santri mana pun (pembayaran,
     * penjadwalan angsuran, dompet, nominal SPP khusus).
     *
     * Hanya `mengundurkan_diri`. `alumni` & `keluar` sengaja TIDAK ikut: keduanya
     * pernah benar-benar bersekolah dan bisa meninggalkan tunggakan SPP yang masih
     * sah ditagih. `tidak_lulus` & `gagal_medcheck` juga tidak, karena tagihan
     * registrasinya sudah dibayar sebelum sampai tahap itu — barisnya jejak
     * kuitansi, bukan tagihan menggantung, dan menyembunyikannya hanya akan
     * menghalangi bila ternyata ada sisa yang benar-benar perlu diselesaikan.
     */
    public const DISEMBUNYIKAN_DARI_PEMILIH = ['mengundurkan_diri'];

    /** Perpindahan yang sah: [status sekarang => tujuan yang diizinkan]. */
    public const TRANSISI = [
        'calon' => ['terbayar'],
        'terbayar' => ['terverifikasi'],
        'terverifikasi' => ['diseleksi'],
        'diseleksi' => ['diterima', 'tidak_lulus'],
        'diterima' => ['lolos_kesehatan', 'gagal_medcheck'],
        // `siap_aktivasi` disisipkan di sini, bukan langsung ke `aktif`: keputusan
        // menerima sudah final, tetapi akibatnya (jurnal akrual, penagihan SPP,
        // ikut kenaikan tingkat) baru berlaku saat tahun ajaran masuknya dimulai.
        'lolos_kesehatan' => ['siap_aktivasi'],
        'siap_aktivasi' => ['aktif'],
        'aktif' => ['alumni', 'keluar'],
    ];

    /**
     * Rantai untuk PENDAFTARAN LANJUTAN (kenaikan jenjang internal). Dua bedanya:
     *  • tahap BERKAS dilewati — santrinya sudah dikenal & dokumennya sudah ada,
     *    jadi `terbayar` langsung ke `diseleksi`;
     *  • berakhir di `naik`, bukan `aktif`. Santrinya memang sudah aktif sejak
     *    sebelum mendaftar; yang berakhir di sini adalah SIKLUS PENDAFTARANNYA.
     */
    public const TRANSISI_LANJUTAN = [
        'calon' => ['terbayar'],
        'terbayar' => ['diseleksi'],
        'diseleksi' => ['diterima', 'tidak_lulus'],
        'diterima' => ['lolos_kesehatan', 'gagal_medcheck'],
        'lolos_kesehatan' => ['naik'],
    ];

    /** Status yang mengakhiri siklus pendaftaran lanjutan. */
    public const FINAL_LANJUTAN = ['naik', 'tidak_lulus', 'gagal_medcheck', 'mengundurkan_diri'];

    private const LABEL = [
        'calon' => 'Calon', 'terbayar' => 'Registrasi Terbayar', 'terverifikasi' => 'Berkas Terverifikasi',
        'diseleksi' => 'Sudah Diseleksi', 'diterima' => 'Diterima', 'lolos_kesehatan' => 'Lolos Kesehatan',
        'siap_aktivasi' => 'Siap Diaktifkan',
        'aktif' => 'Santri Aktif', 'naik' => 'Naik Jenjang', 'alumni' => 'Alumni', 'tidak_lulus' => 'Tidak Lulus',
        'gagal_medcheck' => 'Gagal Med Check', 'mengundurkan_diri' => 'Mengundurkan Diri', 'keluar' => 'Keluar',
    ];

    public static function labelStatus(string $s): string
    {
        return self::LABEL[$s] ?? $s;
    }

    public static function isFinal(string $status, string $jenis = 'baru'): bool
    {
        return in_array($status, $jenis === 'lanjutan' ? self::FINAL_LANJUTAN : self::STATUS_FINAL, true);
    }

    /**
     * $jenis = 'baru' (santri/pendaftar dari luar) atau 'lanjutan' (kenaikan jenjang
     * internal, yang memakai rantai berbeda — lihat TRANSISI_LANJUTAN).
     */
    public static function assertTransisi(string $dari, string $ke, string $jenis = 'baru'): void
    {
        $subjek = $jenis === 'lanjutan' ? 'Pendaftaran' : 'Santri';
        if ($dari === $ke) {
            throw new AppException(422, $subjek.' sudah berstatus "'.self::labelStatus($dari).'".');
        }
        if (self::isFinal($dari, $jenis)) {
            throw new AppException(422, 'Proses '.strtolower($subjek).' ini sudah berakhir dengan status "'.self::labelStatus($dari).'" dan tidak bisa dilanjutkan.');
        }
        $boleh = ($jenis === 'lanjutan' ? self::TRANSISI_LANJUTAN : self::TRANSISI)[$dari] ?? [];
        if (! in_array($ke, $boleh, true)) {
            $daftar = implode(' atau ', array_map([self::class, 'labelStatus'], $boleh));
            throw new AppException(422, 'Dari "'.self::labelStatus($dari).'" tidak bisa langsung ke "'.self::labelStatus($ke).'"'
                .($daftar ? ". Tahap berikutnya yang sah: {$daftar}." : '.'));
        }
    }

    /** Pengunduran diri boleh kapan saja sebelum proses berakhir (tidak membalik jurnal). */
    public static function assertBolehMundur(string $dari, string $jenis = 'baru'): void
    {
        if (self::isFinal($dari, $jenis)) {
            throw new AppException(422, 'Proses santri ini sudah berakhir dengan status "'.self::labelStatus($dari).'".');
        }
    }
}
