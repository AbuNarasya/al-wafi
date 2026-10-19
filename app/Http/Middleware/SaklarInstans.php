<?php

namespace App\Http\Middleware;

use App\Support\Instans;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menutup (404) rute yang TAK dijaga modul hak akses tetapi tak berlaku di instans ini —
 * mis. Portal Wali & notifikasi Midtrans di instans Wafi Mart. Rute ber-modul sudah tertutup
 * lewat HakAksesModul; daftar awalannya di config/instans.php (`awalan_mati`).
 */
class SaklarInstans
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Instans::alamatMati($request->path())) {
            abort(404);
        }

        return $next($request);
    }
}
