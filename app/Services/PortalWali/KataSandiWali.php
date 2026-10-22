<?php

namespace App\Services\PortalWali;

use App\Exceptions\AppException;
use App\Models\SandiWali;
use App\Models\Wali;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * PASSWORD PORTAL WALI (keputusan user 14 Okt 2026).
 *
 * Kode OTP email hanya untuk PERTAMA KALI masuk dan LUPA PASSWORD — keduanya lalu
 * mewajibkan membuat password (SesiWali::WAJIB_SANDI). Sesudahnya: email + password.
 * Password per orang (ayah / ibu / wali); uang tetap dijaga PIN transaksi terpisah.
 *
 * Gagal masuk dibatasi per EMAIL (penebak satu akun) dan per IP (penebak banyak akun);
 * pesannya selalu sama, tak pernah menyebut email terdaftar atau tidak.
 */
class KataSandiWali
{
    public const MIN = 8;

    private const BATAS_EMAIL = [5, 15 * 60];

    private const BATAS_IP = [20, 15 * 60];

    public static function punya(Wali $wali, string $peran): bool
    {
        return SandiWali::where('id_wali', $wali->id)->where('peran', $peran)->exists();
    }

    /** @throws AppException */
    public static function assertSah(string $sandi, string $ulang): void
    {
        if (mb_strlen($sandi) < self::MIN) {
            throw new AppException(422, __('Password minimal :min karakter.', ['min' => self::MIN]));
        }
        if ($sandi !== $ulang) {
            throw new AppException(422, __('Ulangi password dengan persis sama.'));
        }
    }

    /** @throws AppException */
    public function simpan(Wali $wali, string $peran, string $sandi, string $ulang): void
    {
        self::assertSah($sandi, $ulang);
        SandiWali::updateOrCreate(['id_wali' => $wali->id, 'peran' => $peran], ['sandi_hash' => Hash::make($sandi)]);
    }

    public function cocok(Wali $wali, string $peran, string $sandi): bool
    {
        $hash = SandiWali::where('id_wali', $wali->id)->where('peran', $peran)->value('sandi_hash');

        return $hash !== null && Hash::check($sandi, $hash);
    }

    /**
     * Masuk dengan email + password.
     *
     * @return array{wali:Wali, peran:string}
     *
     * @throws AppException
     */
    public function masuk(string $email, string $sandi, ?string $ip): array
    {
        $email = MasukWali::normalEmail($email);
        $kunci = ['email' => ["portal-sandi:email:{$email}", self::BATAS_EMAIL], 'ip' => ['portal-sandi:ip:'.($ip ?? '-'), self::BATAS_IP]];
        foreach ($kunci as [$k, [$maks]]) {
            if (RateLimiter::tooManyAttempts($k, $maks)) {
                throw new AppException(429, __('Terlalu banyak percobaan. Coba lagi dalam :menit menit, atau pakai "Lupa password".',
                    ['menit' => (int) ceil(RateLimiter::availableIn($k) / 60)]));
            }
        }

        $keluarga = (new MasukWali)->cariKeluarga($email);
        if ($keluarga['status'] === 'satu' && $this->cocok($keluarga['wali'], $keluarga['peran'], $sandi)) {
            RateLimiter::clear($kunci['email'][0]);

            return ['wali' => $keluarga['wali'], 'peran' => $keluarga['peran']];
        }
        foreach ($kunci as [$k, [, $detik]]) {
            RateLimiter::hit($k, $detik);
        }

        throw new AppException(422, __('Email atau password salah. Belum punya password? Masuk dengan kode email.'));
    }
}
