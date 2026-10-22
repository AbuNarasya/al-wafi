<?php

namespace App\Http\Middleware;

use App\Support\AlamatPortal;
use App\Support\BahasaPortal as Bahasa;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Memasang bahasa pilihan wali — HANYA untuk alamat portal (/wali…). Layar staf tetap Indonesia.
 * Lihat App\Support\BahasaPortal.
 */
class BahasaPortal
{
    public function handle(Request $request, Closure $next): Response
    {
        // Selain portal: SELALU bahasa bawaan aplikasi — tak pernah mewarisi locale permintaan sebelumnya
        // (penting bila aplikasi kelak berjalan di proses yang hidup lama, dan di test). Konstanta, bukan
        // config('app.locale'): setLocale() ikut menimpa config itu.
        app()->setLocale(AlamatPortal::alamatPortal($request->path()) ? Bahasa::tentukan($request) : Bahasa::BAWAAN);

        return $next($request);
    }
}
