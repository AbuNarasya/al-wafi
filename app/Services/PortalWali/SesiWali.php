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

    public function masuk(Wali $wali, string $peran, Request $request, bool $ingat): void
    {
        Auth::guard('wali')->login($wali);
        // Sesi baru — mencegah session fixation, dan membuang sisa keadaan pendaftar.
        $request->session()->regenerate();
        $request->session()->forget([self::PENDAFTAR, self::MENUNGGU]);
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
        $request->session()->forget([self::PERAN, self::PENDAFTAR, self::MENUNGGU]);
        $request->session()->regenerateToken();
    }
}
