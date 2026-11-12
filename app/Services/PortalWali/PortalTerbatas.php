<?php

namespace App\Services\PortalWali;

use App\Models\Wali;

/**
 * MODE TERBATAS PORTAL WALI (keputusan user 12 Nov 2026, masa uji coba sebelum portal resmi dipakai).
 *
 * Selama saklar `portal.terbatas` menyala, wali biasa hanya bisa: masuk/keluar & urusan password, Data Wali
 * & Santri (termasuk verifikasi link WA), berkas santri, pengaturan akun (perangkat & notifikasi), dan
 * pendaftaran santri baru (PPSB tetap berjalan). Pembayaran, dompet, kartu/PIN, perizinan, penarikan,
 * pengunduran diri, kehadiran, klinik, laundry, kabar, & asisten TERTUTUP — pembukuan ERP baru mulai
 * 1 Jan 2027 dan data uang belum final.
 *
 * Keluarga bertanda `wali.akun_uji` (dicentang admin di Data Wali) tetap bisa memakai SEMUA fungsi.
 * Saklarnya MANUAL (bukan tanggal): dibuka saat user memintanya.
 */
final class PortalTerbatas
{
    /**
     * Alamat yang tetap terbuka bagi wali biasa. Dicocokkan lewat ALAMAT (seperti mode verifikasi),
     * supaya rute POST/PUT tak bernama ikut tercakup.
     */
    public const RUTE_TERBUKA = [
        'wali', 'wali/beranda', 'wali/keluar', 'wali/bahasa',
        'wali/akun', 'wali/akun/*', 'wali/sandi', 'wali/sandi/*',
        'wali/data', 'wali/data/*', 'wali/verifikasi', 'wali/verifikasi/*', 'wali/v', 'wali/v/*',
        'wali/anak/*/berkas', 'wali/berkas/*',
        'wali/daftar', 'wali/daftar-baru',
    ];

    /** Kabar yang tetap dikirim ke wali biasa: hanya soal berkas & data. */
    public const JENIS_KABAR_TERBUKA = ['berkas_perlu_diganti', UbahDataWali::JENIS_NOTIF];

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

    /** Status santri yang masih di alur PPSB — alurnya (termasuk bayar tagihan PPSB) tetap jalan. */
    public const STATUS_PPSB = [...\App\Models\Santri::CALON, 'siap_aktivasi'];

    public const PERILAKU_PPSB = ['registrasi', 'uang_pangkal', 'perlengkapan'];

    /** Alamat ini boleh dibuka keluarga ini? (dipanggil middleware bila mode terbatas menyala) */
    public static function alamatBoleh(\Illuminate\Http\Request $request, Wali $wali): bool
    {
        if (self::bebas($wali) || $request->is(...self::RUTE_TERBUKA)) {
            return true;
        }
        $p = explode('/', trim($request->path(), '/'));
        $id = (int) ($p[2] ?? 0);

        // Pendaftaran PPSB tetap berjalan seperti biasa: halaman anak calon, kartu ujian, tagihan PPSB & pembayarannya.
        if ($request->is('wali/anak/*', 'wali/anak/*/kartu-ujian')) {
            return \App\Models\Santri::whereKey($id)->where('id_wali', $wali->id)->whereIn('status', self::STATUS_PPSB)->exists();
        }
        if ($request->is('wali/bayar/*', 'wali/bayar/*/transfer', 'wali/online/tagihan/*')) {
            $id = (int) ($request->is('wali/online/tagihan/*') ? ($p[3] ?? 0) : $id);

            return \App\Models\TagihanSantri::whereKey($id)->whereIn('perilaku', self::PERILAKU_PPSB)
                ->whereHas('santri', fn ($s) => $s->where('id_wali', $wali->id)->whereIn('status', self::STATUS_PPSB))->exists();
        }

        // Melihat/membatalkan pembayaran online & kuitansi yang SUDAH ada (hanya bisa lahir dari tagihan PPSB di atas).
        // `wali/online/dompet` = isi dompet lewat online — tetap tertutup.
        return ! $request->is('wali/online/dompet') && $request->is('wali/online/*', 'wali/receipt/*');
    }

    public static function pesan(): string
    {
        return __('Fitur ini belum dibuka — aplikasi masih versi demo. Saat ini Anda bisa memperbarui data keluarga dan mengunggah berkas santri.');
    }
}
