<?php

namespace App\Support;

use App\Models\Wali;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * BAHASA PORTAL WALI (keputusan user 22 Okt 2026): Indonesia, Inggris, Arab — HANYA portal wali; layar
 * staf & Wafi Mart tetap Indonesia.
 *
 * Teks ditulis dalam bahasa Indonesia dan dibungkus `__()`; terjemahannya di `lang/en.json` & `lang/ar.json`
 * dengan teks Indonesia sebagai kunci. Kunci yang belum diterjemahkan tampil apa adanya (Indonesia), jadi
 * halaman tak pernah rusak karena terjemahan tertinggal. Isi yang diketik staf & nama dari data master
 * SENGAJA tak diterjemahkan (keputusan user).
 *
 * Urutan penentu: pilihan orang yang masuk (tabel `bahasa_wali`, ikut ke HP lain) → pilihan di sesi
 * (sebelum masuk) → Indonesia.
 */
final class BahasaPortal
{
    /** Nama bahasa dalam bahasanya sendiri — tak diterjemahkan. */
    public const BAHASA = ['id' => 'Bahasa Indonesia', 'en' => 'English', 'ar' => 'العربية'];

    /** Kode pendek di tombol pemilih (gaya pemilih bahasa Windows). */
    public const LABEL = ['id' => 'ID', 'en' => 'ENG', 'ar' => 'ع'];

    /** Baris kedua tiap pilihan — nama bahasanya dalam bahasa yang SEDANG dipakai (dibungkus __() saat tampil). */
    public const KETERANGAN = ['id' => 'Bahasa Indonesia', 'en' => 'Bahasa Inggris', 'ar' => 'Bahasa Arab'];

    public const BAWAAN = 'id';

    /** Bahasa yang ditulis kanan-ke-kiri. */
    public const RTL = ['ar'];

    public const SESI = 'portal.bahasa';

    public static function tentukan(Request $request): string
    {
        $sesi = $request->hasSession() ? $request->session()->get(self::SESI) : null;
        if ($sesi === null && ($wali = \App\Services\PortalWali\SesiWali::wali()) && ($peran = \App\Services\PortalWali\SesiWali::peran($request))) {
            $sesi = self::tersimpan($wali, $peran);
            if ($sesi !== null) {
                $request->session()->put(self::SESI, $sesi);
            }
        }

        return isset(self::BAHASA[$sesi]) ? $sesi : self::BAWAAN;
    }

    public static function tersimpan(Wali $wali, string $peran): ?string
    {
        return DB::table('bahasa_wali')->where('id_wali', $wali->id)->where('peran', $peran)->value('bahasa');
    }

    /** Disimpan di sesi, dan di server bila ada yang masuk (supaya ikut ke perangkat lain). */
    public static function pilih(Request $request, string $bahasa): void
    {
        abort_unless(isset(self::BAHASA[$bahasa]), 422);
        $request->session()->put(self::SESI, $bahasa);
        $wali = \App\Services\PortalWali\SesiWali::wali();
        $peran = \App\Services\PortalWali\SesiWali::peran($request);
        if ($wali && $peran) {
            DB::table('bahasa_wali')->updateOrInsert(['id_wali' => $wali->id, 'peran' => $peran],
                ['bahasa' => $bahasa, 'updated_at' => now(), 'created_at' => now()]);
        }
    }

    /**
     * Label status santri dalam bahasa portal. Kunci "Keluar" sudah dipakai tombol log out, jadi status dicari
     * dulu lewat kunci berkonteks "Status santri: …"; tak ada → kunci biasa. Di locale `id` hasilnya label
     * Indonesia apa adanya (terjemahan JSON tak memakai locale cadangan).
     */
    public static function statusSantri(string $status): string
    {
        $label = \App\Services\Ppsb\Tahap::labelStatus($status);

        return \Illuminate\Support\Facades\Lang::has('Status santri: '.$label) ? __('Status santri: '.$label) : __($label);
    }

    public static function arah(?string $bahasa = null): string
    {
        return in_array($bahasa ?? app()->getLocale(), self::RTL, true) ? 'rtl' : 'ltr';
    }
}
