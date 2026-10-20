<?php

namespace App\Http\Middleware;

use App\Support\Instans;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pintu penghubung dompet toko: hanya pemegang kunci rahasia (config/penghubung.php) yang boleh lewat.
 *
 * Tanda tangan = HMAC-SHA256( rahasia, METHOD \n /path \n waktu \n isi-mentah ), hex, di header
 * `X-Penghubung-Tanda`; `X-Penghubung-Waktu` = detik Unix. Selisih waktu > toleransi DITOLAK, jadi
 * permintaan yang direkam tak bisa diputar ulang belakangan (potong pun idempoten lewat nomornya).
 *
 * Hanya hidup di instans Al Wafi (pemilik dompet) dan bila kuncinya terpasang; selain itu 404.
 */
class TandaTanganToko
{
    public function handle(Request $request, Closure $next): Response
    {
        $rahasia = (string) config('penghubung.rahasia');
        if ($rahasia === '' || Instans::adalah('wafimart')) {
            abort(404);
        }

        $ip = array_filter(array_map('trim', explode(',', (string) config('penghubung.ip'))));
        if ($ip !== [] && ! in_array($request->ip(), $ip, true)) {
            return response()->json(['message' => 'Alamat pengirim tidak diizinkan.'], 403);
        }

        $waktu = (string) $request->header('X-Penghubung-Waktu', '');
        $tanda = (string) $request->header('X-Penghubung-Tanda', '');
        if (! ctype_digit($waktu) || abs(time() - (int) $waktu) > (int) config('penghubung.toleransi_detik', 300)) {
            return response()->json(['message' => 'Waktu permintaan tidak sah atau kedaluwarsa.'], 401);
        }
        $harap = self::tanda($rahasia, $request->method(), '/'.ltrim($request->path(), '/'), $waktu, $request->getContent());
        if ($tanda === '' || ! hash_equals($harap, $tanda)) {
            return response()->json(['message' => 'Tanda tangan tidak sah.'], 401);
        }

        return $next($request);
    }

    /** Satu rumus untuk pengirim & penerima. */
    public static function tanda(string $rahasia, string $method, string $path, string $waktu, string $isi): string
    {
        return hash_hmac('sha256', strtoupper($method)."\n".$path."\n".$waktu."\n".$isi, $rahasia);
    }
}
