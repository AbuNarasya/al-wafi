<?php

namespace App\Services\PortalWali;

use App\Exceptions\AppException;
use App\Models\OtpWali;
use App\Models\Wali;
use App\Notifications\KodeMasukWali;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

/**
 * MASUK PORTAL WALI — email → kode 6 angka → masuk.
 *
 * SATU PINTU untuk masuk & mendaftar: kode dikirim ke email apa pun yang sah,
 * terdaftar atau tidak. Layar tak pernah mengatakan "email ini belum terdaftar"
 * sebelum kodenya benar — kalau tidak, portal bisa dipakai menebak email wali.
 * Sesudah kode benar barulah ditentukan: masuk, mendaftar, atau ditolak.
 *
 * Kode dikirim ke email yang DIKETIK, dan memasukkan kode yang benar adalah
 * bukti menguasai email itu. Itulah sebabnya identitas = email: tak ada jalan
 * masuk ke sebuah keluarga tanpa menguasai email yang tercatat di datanya.
 */
class MasukWali
{
    public const MENIT = 10;

    public const JEDA_DETIK = 60;

    public const MAKS_SALAH = 5;

    /** Batas minta kode per email & per IP — melindungi kotak masuk orang dan kuota SMTP. */
    private const BATAS_EMAIL = [3, 15 * 60];

    private const BATAS_IP = [10, 60 * 60];

    public const PERAN = ['ayah', 'ibu', 'wali'];

    public static function normalEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /** @throws AppException */
    public function mintaKode(string $email, string $tujuan, ?string $ip): void
    {
        $email = self::normalEmail($email);
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new AppException(422, 'Alamat email tidak sah.');
        }

        $terakhir = OtpWali::where('email', $email)->where('tujuan', $tujuan)->latest('id')->value('created_at');
        if ($terakhir && now()->diffInSeconds($terakhir, true) < self::JEDA_DETIK) {
            throw new AppException(429, 'Kode baru saja dikirim. Tunggu satu menit sebelum meminta lagi.');
        }
        foreach (['email' => [$email, self::BATAS_EMAIL], 'ip' => [$ip ?? '-', self::BATAS_IP]] as $jenis => [$nilai, [$maks, $detik]]) {
            $kunci = "portal-otp:{$jenis}:{$nilai}";
            if (RateLimiter::tooManyAttempts($kunci, $maks)) {
                $menit = (int) ceil(RateLimiter::availableIn($kunci) / 60);
                throw new AppException(429, "Terlalu banyak permintaan kode. Coba lagi dalam {$menit} menit.");
            }
            RateLimiter::hit($kunci, $detik);
        }

        $kode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        DB::transaction(function () use ($email, $tujuan, $kode, $ip) {
            // Kode lama yang belum terpakai gugur: hanya kode TERBARU yang berlaku.
            OtpWali::where('email', $email)->where('tujuan', $tujuan)->whereNull('dipakai_pada')
                ->update(['kedaluwarsa_pada' => now()]);
            OtpWali::create([
                'email' => $email, 'tujuan' => $tujuan, 'kode_hash' => Hash::make($kode),
                'kedaluwarsa_pada' => now()->addMinutes(self::MENIT), 'ip' => $ip,
            ]);
        });

        Notification::route('mail', $email)->notify(new KodeMasukWali($kode, self::MENIT));
    }

    /** Benar = kode terbaru, belum kedaluwarsa, belum dipakai. Lima kali salah = kodenya hangus. */
    public function periksa(string $email, string $tujuan, string $kode): bool
    {
        $email = self::normalEmail($email);
        $kode = preg_replace('/\D/', '', $kode);

        return DB::transaction(function () use ($email, $tujuan, $kode) {
            $otp = OtpWali::where('email', $email)->where('tujuan', $tujuan)
                ->whereNull('dipakai_pada')->where('kedaluwarsa_pada', '>', now())
                ->latest('id')->lockForUpdate()->first();
            if (! $otp || $kode === '' || $otp->percobaan >= self::MAKS_SALAH) {
                return false;
            }
            if (! Hash::check($kode, $otp->kode_hash)) {
                $otp->increment('percobaan');

                return false;
            }
            $otp->update(['dipakai_pada' => now()]);

            return true;
        });
    }

    /**
     * Keluarga pemilik email ini.
     *
     * @return array{status:'satu', wali:Wali, peran:string}|array{status:'baru'|'kembar'|'nonaktif'}
     */
    public function cariKeluarga(string $email): array
    {
        $email = self::normalEmail($email);
        $cocok = Wali::where(function ($q) use ($email) {
            foreach (self::PERAN as $p) {
                $q->orWhereRaw("lower(trim(email_{$p})) = ?", [$email]);
            }
        })->get();

        if ($cocok->isEmpty()) {
            return ['status' => 'baru'];
        }
        // Dompet melekat per keluarga — portal tak boleh menebak keluarga yang mana.
        if ($cocok->count() > 1) {
            return ['status' => 'kembar'];
        }

        $wali = $cocok->first();
        if ($wali->status !== 'aktif') {
            return ['status' => 'nonaktif'];
        }

        return ['status' => 'satu', 'wali' => $wali, 'peran' => self::peranDariEmail($wali, $email)];
    }

    /** Peran yang emailnya cocok; kontak utama didahulukan bila ayah & ibu berbagi alamat. */
    public static function peranDariEmail(Wali $wali, string $email): string
    {
        $urutan = array_unique([$wali->kontak_utama, ...self::PERAN]);
        foreach ($urutan as $p) {
            if (self::normalEmail((string) $wali->{"email_{$p}"}) === $email) {
                return $p;
            }
        }

        return (string) $wali->kontak_utama;
    }

    public static function samarkanEmail(string $email): string
    {
        [$nama, $domain] = explode('@', $email, 2) + [1 => ''];

        return mb_substr($nama, 0, 1).str_repeat('*', max(2, mb_strlen($nama) - 1)).'@'.$domain;
    }
}
