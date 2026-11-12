<?php

namespace App\Services\PortalWali;

use App\Models\Wali;

/**
 * MODE TERBATAS PORTAL WALI (keputusan user 12 Nov 2026, masa uji coba sebelum portal resmi dipakai).
 *
 * Tampilan TETAP LENGKAP: semua menu & halaman bisa DIBUKA untuk melihat fungsinya (koreksi user 12 Nov 2026:
 * "ditampilkan lengkap, bisa dibuka, tetapi tidak bisa digunakan"). Yang dikunci adalah TINDAKANNYA (setiap
 * permintaan selain GET/HEAD): bayar, isi/pindah dompet, kartu, perizinan, penarikan, pengunduran diri,
 * asisten, … — pembukuan ERP baru mulai 1 Jan 2027 dan data uang belum final.
 *
 * Tindakan yang tetap berfungsi: Data Wali & Santri (termasuk verifikasi link WA), berkas santri, akun
 * (password, PIN, perangkat, notifikasi, tata menu, bahasa), pendaftaran santri baru, dan pembayaran
 * tagihan PPSB calon santri (alur PPSB tetap berjalan).
 *
 * Keluarga bertanda `wali.akun_uji` (dicentang admin di Data Wali) bisa menggunakan SEMUA fungsi.
 * Saklarnya MANUAL (`portal.terbatas`), bukan tanggal: dibuka saat user memintanya.
 */
final class PortalTerbatas
{
    /**
     * Tindakan (POST/PUT/DELETE) yang tetap berfungsi bagi wali biasa. Dicocokkan lewat ALAMAT (seperti mode
     * verifikasi), supaya rute tak bernama ikut tercakup.
     */
    public const TINDAKAN_TERBUKA = [
        'wali/keluar', 'wali/bahasa', 'wali/menu',
        'wali/akun/*', 'wali/sandi', 'wali/sandi/*', 'wali/pin/*',
        'wali/data', 'wali/data/*', 'wali/verifikasi', 'wali/verifikasi/*', 'wali/v', 'wali/v/*',
        'wali/anak/*/berkas', 'wali/berkas/*',
        'wali/daftar', 'wali/daftar-baru',
    ];

    /** Kabar yang tetap dikirim ke wali biasa: hanya soal berkas & data. */
    public const JENIS_KABAR_TERBUKA = ['berkas_perlu_diganti', UbahDataWali::JENIS_NOTIF];

    /** Status santri yang masih di alur PPSB — pembayaran tagihan PPSB-nya tetap berfungsi. */
    public const STATUS_PPSB = [...\App\Models\Santri::CALON, 'siap_aktivasi'];

    public const PERILAKU_PPSB = ['registrasi', 'uang_pangkal', 'perlengkapan'];

    public static function aktif(): bool
    {
        return (bool) config('portal.terbatas');
    }

    /** Keluarga ini boleh memakai semua fungsi (saklar mati, atau akun uji). */
    public static function bebas(?Wali $wali): bool
    {
        return ! self::aktif() || (bool) $wali?->akun_uji;
    }

    public static function kabarBoleh(?int $idWali, ?string $jenis): bool
    {
        if (! self::aktif() || in_array($jenis, self::JENIS_KABAR_TERBUKA, true)) {
            return true;
        }

        return $idWali !== null && (bool) Wali::whereKey($idWali)->value('akun_uji');
    }

    /** Permintaan ini boleh dijalankan keluarga ini? (dipanggil middleware bila mode terbatas menyala) */
    public static function alamatBoleh(\Illuminate\Http\Request $request, Wali $wali): bool
    {
        // Melihat selalu boleh — tampilan portal tetap lengkap.
        if (self::bebas($wali) || $request->isMethodSafe() || $request->is(...self::TINDAKAN_TERBUKA)) {
            return true;
        }
        // Alur PPSB: membayar tagihan PPSB calon santri (transfer / online) tetap berfungsi.
        if ($request->is('wali/bayar/*/transfer', 'wali/online/tagihan/*')) {
            $p = explode('/', trim($request->path(), '/'));
            $id = (int) ($request->is('wali/online/tagihan/*') ? ($p[3] ?? 0) : ($p[2] ?? 0));

            return \App\Models\TagihanSantri::whereKey($id)->whereIn('perilaku', self::PERILAKU_PPSB)
                ->whereHas('santri', fn ($s) => $s->where('id_wali', $wali->id)->whereIn('status', self::STATUS_PPSB))->exists();
        }

        // Membatalkan pembayaran online yang SUDAH ada (lahir dari tagihan PPSB di atas) tetap boleh.
        return $request->is('wali/online/*/batal');
    }

    public static function pesan(): string
    {
        return __('Fitur ini belum bisa digunakan — aplikasi masih versi demo. Saat ini Anda bisa memperbarui data keluarga dan mengunggah berkas santri.');
    }
}
