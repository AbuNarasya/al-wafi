<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\ResetSandiStaf;
use App\Models\User;
use App\Notifications\PemberitahuanAkunStaf;
use App\Notifications\TautanResetSandiStaf;
use App\Support\Audit\Jejak;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * RESET KATA SANDI STAF LEWAT EMAIL (keputusan user 12 Nov 2026).
 *
 *  - Diisi USERNAME atau EMAIL; tautan selalu dikirim ke email yang tercatat di akunnya.
 *  - Pesan TERANG (pilihan user): akun tak terdaftar / nonaktif / belum beremail disebut apa adanya. Menebak
 *    daftar akun dibatasi jatah per perangkat (BATAS_IP), dihitung SEBELUM akunnya dicari.
 *  - Tautan berlaku MENIT menit, sekali pakai; meminta lagi menggugurkan tautan lama. Yang disimpan hanya hash.
 *  - Setelah sandi diganti: semua sesi & "ingat saya" akun itu dikeluarkan, jejak audit ditulis, dan pemilik
 *    akun dikabari lewat email.
 */
class ResetSandiStafService
{
    public const MENIT = 30;

    private const BATAS_AKUN = [3, 15 * 60];

    private const BATAS_IP = [10, 60 * 60];

    /** Akun yang dimaksud isian (username, atau email bila ada "@"), tanpa membedakan huruf besar. */
    public static function cariAkun(string $isian): ?User
    {
        $isian = mb_strtolower(trim($isian));
        if ($isian === '') {
            return null;
        }
        $kolom = str_contains($isian, '@') ? 'email' : 'username';

        return User::whereRaw("lower(trim({$kolom})) = ?", [$isian])->first();
    }

    /**
     * Kirim tautan reset. Kembalian: alamat email tujuan yang disamarkan, untuk ditampilkan.
     *
     * @throws AppException
     */
    public function minta(string $isian, ?string $ip): string
    {
        $this->batasi('ip', $ip ?? '-', self::BATAS_IP);

        $user = self::cariAkun($isian);
        if (! $user) {
            throw new AppException(422, 'Username atau email tidak terdaftar. Periksa kembali penulisannya.');
        }
        if ($user->status !== 'aktif') {
            throw new AppException(422, 'Akun ini nonaktif. Hubungi administrator.');
        }
        if (blank($user->email)) {
            throw new AppException(422, 'Akun ini belum punya email, jadi tautan reset tak bisa dikirim. '
                .'Minta administrator mengisi email Anda di Pengguna, atau meminta ia mengganti kata sandi Anda.');
        }
        $this->batasi('akun', (string) $user->id_pengguna, self::BATAS_AKUN);

        $token = Str::random(64);
        DB::transaction(function () use ($user, $token, $ip) {
            // Hanya tautan TERBARU yang berlaku.
            ResetSandiStaf::where('id_pengguna', $user->id_pengguna)->whereNull('dipakai_pada')
                ->where('kedaluwarsa_pada', '>', now())->update(['kedaluwarsa_pada' => now()]);
            ResetSandiStaf::create([
                'id_pengguna' => $user->id_pengguna, 'token_hash' => hash('sha256', $token),
                'kedaluwarsa_pada' => now()->addMinutes(self::MENIT), 'ip' => $ip,
            ]);
        });
        $user->notify(new TautanResetSandiStaf(route('sandi_staf.reset', $token), self::MENIT));

        return self::samarkan((string) $user->email);
    }

    /** Baris tautan yang masih berlaku untuk token ini, atau null. */
    public function tautanBerlaku(string $token): ?ResetSandiStaf
    {
        $baris = ResetSandiStaf::with('pengguna')->where('token_hash', hash('sha256', $token))->first();
        if (! $baris || $baris->dipakai_pada || $baris->kedaluwarsa_pada->isPast() || $baris->pengguna?->status !== 'aktif') {
            return null;
        }

        return $baris;
    }

    /** @throws AppException */
    public function setel(string $token, string $sandiBaru, ?string $ip): User
    {
        $user = DB::transaction(function () use ($token, $sandiBaru, $ip) {
            $baris = $this->tautanBerlaku($token);
            if (! $baris) {
                throw new AppException(422, 'Tautan ini sudah tidak berlaku. Minta tautan baru.');
            }
            // Dikunci & diperiksa ulang supaya dua klik bersamaan tak memakai tautan yang sama dua kali.
            $terkunci = ResetSandiStaf::whereKey($baris->id)->whereNull('dipakai_pada')->lockForUpdate()->first();
            if (! $terkunci) {
                throw new AppException(422, 'Tautan ini sudah dipakai. Minta tautan baru.');
            }
            $terkunci->update(['dipakai_pada' => now()]);

            $user = $baris->pengguna;
            self::gantiSandi($user, $sandiBaru, false);
            Jejak::catat('reset_sandi_email', [
                'id_pengguna' => $user->id_pengguna, 'modul' => 'user', 'ref_jenis' => 'users', 'ref_id' => $user->id_pengguna,
                'detail' => ['username' => $user->username, 'ip' => $ip],
            ]);

            return $user;
        });

        self::kabari($user, 'Kata sandi diganti', 'Kata sandi akun Anda baru saja diganti lewat tautan reset email. '
            .'Semua perangkat yang sedang masuk dengan akun ini sudah dikeluarkan.');

        return $user;
    }

    /**
     * Satu pintu penggantian sandi oleh pihak lain (tautan email / admin): hash baru, tanda wajib-ganti,
     * dan SEMUA sesi + token "ingat saya" akun itu gugur — siapa pun yang sedang memakainya harus masuk ulang.
     */
    public static function gantiSandi(User $user, string $sandiBaru, bool $wajibGanti): void
    {
        $user->forceFill([
            'password_hash' => Hash::make($sandiBaru),
            'harus_ganti_sandi' => $wajibGanti,
            'remember_token' => Str::random(60),
        ])->save();
        DB::table('sessions')->where('user_id', $user->id_pengguna)->delete();
    }

    /** Email pemberitahuan keamanan — gagal kirim tak boleh menggagalkan tindakannya. */
    public static function kabari(User|string $tujuan, string $judul, string $isi, ?string $username = null): void
    {
        try {
            if ($tujuan instanceof User) {
                if (filled($tujuan->email)) {
                    $tujuan->notify(new PemberitahuanAkunStaf($judul, $isi, $tujuan->username));
                }

                return;
            }
            \Illuminate\Support\Facades\Notification::route('mail', $tujuan)
                ->notify(new PemberitahuanAkunStaf($judul, $isi, (string) $username));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function samarkan(string $email): string
    {
        [$nama, $domain] = explode('@', $email, 2) + [1 => ''];

        return mb_substr($nama, 0, 1).str_repeat('*', max(2, mb_strlen($nama) - 1)).'@'.$domain;
    }

    /** @param  array{0:int,1:int}  $batas */
    private function batasi(string $jenis, string $nilai, array $batas): void
    {
        [$maks, $detik] = $batas;
        $kunci = "reset-sandi-staf:{$jenis}:{$nilai}";
        if (RateLimiter::tooManyAttempts($kunci, $maks)) {
            $menit = (int) ceil(RateLimiter::availableIn($kunci) / 60);
            throw new AppException(429, "Terlalu banyak permintaan reset. Coba lagi dalam {$menit} menit.");
        }
        RateLimiter::hit($kunci, $detik);
    }
}
