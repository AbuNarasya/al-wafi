<?php

namespace App\Http\Middleware;

use App\Support\AlamatPortal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gerbang DOMAIN portal wali (config/portal.php). Tanpa domain portal diisi, tak berbuat apa-apa.
 *
 *  • Di domain portal: "/" → /wali/; selain /wali/… → 404 (layar staf & login staf tak terbuka dari alamat wali).
 *  • Di domain lain: GET /wali/… dialihkan permanen ke domain portal (link lama tetap jalan). POST dibiarkan —
 *    halaman lama yang masih terbuka tetap bisa menyelesaikan kirimannya; muat ulang berikutnya sudah pindah.
 */
class DomainPortal
{
    public function handle(Request $request, Closure $next): Response
    {
        $domain = AlamatPortal::domain();
        if (! $domain) {
            return $next($request);
        }
        $path = $request->path();

        if (strtolower($request->getHost()) === $domain) {
            if ($path === '/') {
                return redirect('/wali/');
            }
            abort_unless(AlamatPortal::alamatPortal($path) || $path === 'up', 404);

            return $next($request);
        }

        if (AlamatPortal::alamatPortal($path) && $request->isMethod('GET')) {
            $qs = $request->getQueryString();

            return redirect()->away('https://'.$domain.'/'.ltrim($path, '/').($qs ? '?'.$qs : ''), 301);
        }

        return $next($request);
    }
}
