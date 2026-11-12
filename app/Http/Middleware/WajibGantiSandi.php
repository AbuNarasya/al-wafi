<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sandi buatan ADMIN (akun baru / Ubah Pengguna) hanya sementara — keputusan user 12 Nov 2026. Selama
 * `harus_ganti_sandi` menyala, staf hanya bisa membuka Profil (untuk mengganti sandinya) dan keluar.
 * Portal wali & API tak tersentuh (guard/pengaman lain).
 */
class WajibGantiSandi
{
    private const BOLEH = ['profil', 'profil/kata-sandi', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();
        if (! $user || ! $user->harus_ganti_sandi || $request->is(...self::BOLEH) || $request->is('wali', 'wali/*', 'api/*')) {
            return $next($request);
        }

        return $request->expectsJson()
            ? response()->json(['message' => 'Buat kata sandi Anda sendiri dulu di Profil.'], 403)
            : redirect()->route('profil.index')->with('error', 'Kata sandi Anda dibuat oleh administrator. Buat kata sandi Anda sendiri dulu sebelum melanjutkan.');
    }
}
