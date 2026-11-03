<?php

namespace App\Http\Middleware;

use App\Support\Instans;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pintu penerima kiriman ISE (absensi asrama, dst.): header `Authorization: Bearer <ISE_TOKEN>`.
 * Mati (404) bila ISE_TOKEN belum dipasang di .env, dan di instans Wafi Mart. Batasan IP opsional (`ISE_IP`).
 */
class TokenIse
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) config('services.ise.token');
        if ($token === '' || Instans::adalah('wafimart')) {
            abort(404);
        }
        $ip = array_filter(array_map('trim', explode(',', (string) config('services.ise.ip'))));
        if ($ip !== [] && ! in_array($request->ip(), $ip, true)) {
            return response()->json(['message' => 'Alamat pengirim tidak diizinkan.'], 403);
        }
        if (! hash_equals($token, (string) $request->bearerToken())) {
            return response()->json(['message' => 'Token tidak sah.'], 401);
        }

        return $next($request);
    }
}
