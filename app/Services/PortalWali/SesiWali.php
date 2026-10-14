<?php

namespace App\Services\PortalWali;

use App\Models\Wali;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Sesi portal wali: KELUARGA-nya di guard `wali`, ORANG-nya (ayah/ibu/wali) di sesi.
 *
 * Satu keadaan lagi di antara "tamu" dan "masuk": PENDAFTAR — emailnya sudah
 * terbukti lewat kode, tetapi belum punya keluarga. Ia hanya boleh membuka
 * formulir pendaftaran; begitu terdaftar, ia masuk seperti wali biasa.
 */
class SesiWali
{
    public const PERAN = 'portal.peran';

    public const PENDAFTAR = 'portal.pendaftar_email';

    public const MENUNGGU = 'portal.email_menunggu';

    /**
     * MODE VERIFIKASI: masuk lewat link verifikasi data (LinkVerifikasi), bukan email.
     * Berisi id link-nya. Selama ada, middleware PortalWali hanya membuka layar
     * verifikasi & Data Wali & Santri — dompet dan pembayaran tetap tertutup.
     */
    public const VERIFIKASI = 'portal.verifikasi';

    /** Email peran ini yang sudah terbukti dengan kode selama mode verifikasi. */
    public const VERIFIKASI_EMAIL = 'portal.verifikasi_email';

    /**
     * WAJIB MEMBUAT PASSWORD: masuk dengan kode email (pertama kali / lupa password).
     * Selama ada, middleware PortalWali hanya membuka layar password (keputusan user
     * 14 Okt 2026 — kode OTP cukup sekali, sesudahnya email + password).
     */
    public const WAJIB_SANDI = 'portal.wajib_sandi';

    /** Kode email diminta lewat "Lupa password" — sesudah kode benar, password lama diganti. */
    public const LUPA_SANDI = 'portal.lupa_sandi';

    /** Orang yang masuk sudah punya password — diingat per sesi supaya tak ditanya tiap halaman. */
    public const PUNYA_SANDI = 'portal.punya_sandi';

    public function __construct(private readonly IngatPerangkat $perangkat = new IngatPerangkat) {}

    public static function wali(): ?Wali
    {
        $wali = Auth::guard('wali')->user();

        return $wali instanceof Wali ? $wali : null;
    }

    public static function peran(Request $request): ?string
    {
        return $request->session()->get(self::PERAN);
    }

    /** Orang yang sedang masuk sudah punya PIN transaksi? (untuk papan PIN di layar) */
    public static function punyaPin(): bool
    {
        $wali = self::wali();
        $peran = request()->session()->get(self::PERAN);

        return $wali && $peran && (new PinTransaksi($wali, $peran))->punya();
    }

    public static function emailPendaftar(Request $request): ?string
    {
        return $request->session()->get(self::PENDAFTAR);
    }

    public static function modeVerifikasi(Request $request): ?int
    {
        $id = $request->session()->get(self::VERIFIKASI);

        return $id ? (int) $id : null;
    }

    /** Masuk TERBATAS lewat link verifikasi — tanpa "ingat perangkat". */
    public function masukVerifikasi(Wali $wali, string $peran, int $idLink, Request $request): void
    {
        Auth::guard('wali')->login($wali);
        $request->session()->regenerate();
        $request->session()->forget([self::PENDAFTAR, self::MENUNGGU, self::VERIFIKASI_EMAIL, self::WAJIB_SANDI, self::PUNYA_SANDI]);
        $request->session()->put(self::PERAN, $peran);
        $request->session()->put(self::VERIFIKASI, $idLink);
    }

    /** Verifikasi selesai: sesi terbatas naik menjadi sesi portal penuh. */
    public function akhiriVerifikasi(Request $request): void
    {
        $request->session()->regenerate();
        $request->session()->forget([self::VERIFIKASI, self::VERIFIKASI_EMAIL]);
    }

    public function masuk(Wali $wali, string $peran, Request $request, bool $ingat): void
    {
        Auth::guard('wali')->login($wali);
        // Sesi baru — mencegah session fixation, dan membuang sisa keadaan pendaftar.
        $request->session()->regenerate();
        $request->session()->forget([self::PENDAFTAR, self::MENUNGGU, self::VERIFIKASI, self::VERIFIKASI_EMAIL, self::WAJIB_SANDI, self::PUNYA_SANDI]);
        $request->session()->put(self::PERAN, $peran);
        if ($ingat) {
            $this->perangkat->ingat($wali, $peran, $request);
        }
    }

    /** Dari cookie "ingat perangkat" — tanpa kode lagi. */
    public function pulihkan(Request $request): bool
    {
        $p = $this->perangkat->kenali($request);
        if (! $p) {
            return false;
        }
        Auth::guard('wali')->login($p->wali);
        $request->session()->regenerate();
        $request->session()->put(self::PERAN, $p->peran);

        return true;
    }

    public function jadikanPendaftar(string $email, Request $request): void
    {
        $request->session()->regenerate();
        $request->session()->forget(self::MENUNGGU);
        $request->session()->put(self::PENDAFTAR, MasukWali::normalEmail($email));
    }

    public function keluar(Request $request): void
    {
        $this->perangkat->lupakan($request);
        Auth::guard('wali')->logout();
        $request->session()->forget([self::PERAN, self::PENDAFTAR, self::MENUNGGU, self::VERIFIKASI, self::VERIFIKASI_EMAIL,
            self::WAJIB_SANDI, self::LUPA_SANDI, self::PUNYA_SANDI]);
        $request->session()->regenerateToken();
    }
}
