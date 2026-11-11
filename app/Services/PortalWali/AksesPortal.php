<?php

namespace App\Services\PortalWali;

use App\Models\DompetSantri;
use App\Models\DompetWali;
use App\Models\Santri;
use App\Models\TabunganSantri;
use App\Models\TagihanSantri;
use App\Models\Wali;
use App\Services\Ppsb\Tahap;

/**
 * AKSES PORTAL KELUARGA (keputusan user 11 Nov 2026).
 *
 * Keluarga yang seluruh anaknya sudah berakhir (keluar, calon mundur, alumni, tak lulus seleksi) TIDAK lagi
 * bisa membuka portal — KECUALI masih ada urusan yang hanya bisa diselesaikan di sana:
 *   • ada anak yang belum berakhir (santri aktif ATAU calon yang sedang mendaftar),
 *   • ada sisa tagihan (santri aktif yang keluar tetap ditagih — keputusan 22 Okt 2026),
 *   • ada saldo Dompet Wali, Dompet Santri, atau Tabungan Santri yang belum ditarik.
 * Begitu semuanya habis, portal tertutup; begitu ada anak aktif/calon lagi, terbuka sendiri.
 *
 * SENGAJA dihitung, bukan disimpan: status `wali.status` tetap saklar MANUAL milik staf, dan tak perlu jadwal
 * harian untuk menutup/membuka. Keluarga tanpa anak sama sekali (baru mendaftar, belum menambah anak) tetap terbuka.
 */
final class AksesPortal
{
    public static function terbuka(Wali $wali): bool
    {
        if ($wali->status !== 'aktif') {
            return false;
        }

        $anak = Santri::where('id_wali', $wali->id);
        if (! (clone $anak)->exists() || (clone $anak)->whereNotIn('status', Tahap::STATUS_FINAL)->exists()) {
            return true;
        }

        $idAnak = (clone $anak)->pluck('id');

        return TagihanSantri::whereIn('id_santri', $idAnak)->whereNotIn('status', TagihanSantri::TIDAK_BERLAKU)->where('sisa', '>', 0)->exists()
            || DompetWali::where('id_wali', $wali->id)->where('saldo', '>', 0)->exists()
            || DompetSantri::whereIn('id_santri', $idAnak)->where('saldo', '>', 0)->exists()
            || TabunganSantri::whereIn('id_santri', $idAnak)->where('saldo', '>', 0)->exists();
    }

    /** Pesan untuk keluarga yang portalnya tertutup (dinonaktifkan staf atau tak ada lagi santri). */
    public static function pesan(Wali $wali): string
    {
        return $wali->status !== 'aktif'
            ? __('Akun keluarga ini sedang dinonaktifkan. Silakan hubungi panitia.')
            : __('Akun Portal Wali keluarga ini sudah tidak aktif karena tidak ada lagi santri yang terdaftar di pesantren. Bila ada keperluan, silakan hubungi pesantren.');
    }
}
