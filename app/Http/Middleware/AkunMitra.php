<?php

namespace App\Http\Middleware;

use App\Models\Mitra;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * AKUN MITRA KANTIN (9 Nov 2026) — pengguna ber-`id_mitra` hanya boleh membuka layar /mitra miliknya, keluar,
 * dan Profil (ganti kata sandi). Alamat lain dialihkan ke kasir mitra, sehingga akun mitra tak pernah melihat
 * menu ERP walau pernah diberi hak modul apa pun. Mitra/akun yang dinonaktifkan langsung dikeluarkan.
 *
 * Dipasang di grup `web` (bootstrap/app.php). Rute /mitra sendiri dijaga `mitra.saja` (staf biasa ditolak).
 */
class AkunMitra
{
    private const BOLEH = ['mitra', 'mitra/*', 'logout', 'profil', 'profil/*'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();
        if (! $user || $user->id_mitra === null) {
            return $next($request);
        }
        $mitra = Mitra::find($user->id_mitra);
        if (! $mitra || ! $mitra->aktif() || $user->status !== 'aktif') {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('error', 'Akun mitra ini sedang nonaktif — hubungi petugas Wafi Mart.');
        }
        if (! $request->is(...self::BOLEH)) {
            return $request->expectsJson() ? response()->json(['message' => 'Akun mitra hanya untuk layar kasir mitra.'], 403)
                : redirect()->route('mitra.kasir');
        }

        return $next($request);
    }
}
