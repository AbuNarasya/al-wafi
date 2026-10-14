<?php

namespace App\Services\PortalWali;

use App\Models\PerangkatWali;
use App\Models\Wali;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * "Ingat perangkat ini" — wali tak diminta kode tiap kali sesinya habis.
 *
 * Token acak di cookie httpOnly (dienkripsi Laravel); di basis data hanya
 * sha256-nya, jadi bocornya tabel tak membuka perangkat siapa pun.
 *
 * Diingat SELAMANYA (keputusan user 14 Okt 2026; dulu 90 hari): gugur hanya bila
 * wali keluar, perangkatnya dicabut, password-nya diganti, atau email keluarganya
 * diubah (Wali::booted). Browser membatasi umur cookie ±400 hari, jadi cookie-nya
 * diperpanjang tiap dipakai — perangkat yang dibuka sekali setahun tak pernah lepas.
 */
class IngatPerangkat
{
    public const COOKIE = 'alwafi_wali';

    /** Umur cookie (hari) — batas browser modern; diperbarui tiap perangkat dipakai. */
    public const HARI = 400;

    public function ingat(Wali $wali, string $peran, Request $request): PerangkatWali
    {
        $token = Str::random(64);
        $perangkat = PerangkatWali::create([
            'id_wali' => $wali->id, 'peran' => $peran, 'token_hash' => hash('sha256', $token),
            'nama_perangkat' => self::namaPerangkat((string) $request->userAgent()),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            'ip_terakhir' => $request->ip(), 'terakhir_dipakai' => now(),
            'kedaluwarsa_pada' => null,   // selamanya
        ]);
        $this->pasangCookie($token);

        return $perangkat;
    }

    /** Perangkat yang masih berlaku dari cookie ini; null bila tak ada / dicabut / kedaluwarsa. */
    public function kenali(Request $request): ?PerangkatWali
    {
        $token = (string) $request->cookie(self::COOKIE);
        if ($token === '') {
            return null;
        }
        $perangkat = PerangkatWali::with('wali')->where('token_hash', hash('sha256', $token))->first();
        if (! $perangkat || ! $perangkat->berlaku() || ! $perangkat->wali || $perangkat->wali->status !== 'aktif') {
            Cookie::queue(Cookie::forget(self::COOKIE));

            return null;
        }
        // Diperpanjang paling sering sekali sejam — tidak menulis ke basis data tiap permintaan.
        if ($perangkat->terakhir_dipakai === null || $perangkat->terakhir_dipakai->lt(now()->subHour())) {
            $perangkat->update(['terakhir_dipakai' => now(), 'ip_terakhir' => $request->ip()]);
            $this->pasangCookie($token);
        }

        return $perangkat;
    }

    /** Keluar: perangkat ini dicabut dan cookienya dihapus. */
    public function lupakan(Request $request): void
    {
        $token = (string) $request->cookie(self::COOKIE);
        if ($token !== '') {
            PerangkatWali::where('token_hash', hash('sha256', $token))->whereNull('dicabut_pada')
                ->update(['dicabut_pada' => now()]);
        }
        Cookie::queue(Cookie::forget(self::COOKIE));
    }

    public static function cabutSemua(int $idWali): void
    {
        PerangkatWali::where('id_wali', $idWali)->whereNull('dicabut_pada')
            ->update(['dicabut_pada' => now(), 'updated_at' => now()]);
    }

    /**
     * Password ORANG ini diganti / disetel ulang: perangkatnya yang lain dicabut —
     * perangkat yang diingat selamanya tak boleh tetap terbuka bagi pemegang HP lama.
     * Perangkat yang sedang dipakai (cookie permintaan ini) dibiarkan.
     */
    public function cabutLainnya(int $idWali, string $peran, Request $request): void
    {
        $token = (string) $request->cookie(self::COOKIE);
        PerangkatWali::where('id_wali', $idWali)->where('peran', $peran)->whereNull('dicabut_pada')
            ->when($token !== '', fn ($q) => $q->where('token_hash', '!=', hash('sha256', $token)))
            ->update(['dicabut_pada' => now(), 'updated_at' => now()]);
    }

    /** "Android · Chrome" dari user agent. */
    public static function namaPerangkat(string $ua): string
    {
        $sistem = match (true) {
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS') => 'Mac',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Perangkat lain',
        };
        $peramban = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Firefox') => 'Firefox',
            str_contains($ua, 'Chrome') => 'Chrome',
            str_contains($ua, 'Safari') => 'Safari',
            default => null,
        };

        return $peramban ? "{$sistem} · {$peramban}" : $sistem;
    }

    private function pasangCookie(string $token): void
    {
        Cookie::queue(Cookie::make(self::COOKIE, $token, self::HARI * 24 * 60, null, null, null, true, false, 'lax'));
    }
}
