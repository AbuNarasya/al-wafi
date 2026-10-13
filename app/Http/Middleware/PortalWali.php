<?php

namespace App\Http\Middleware;

use App\Services\PortalWali\SesiWali;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gerbang rute portal wali.
 *
 *   portal.wali             — wajib keluarga yang sudah masuk;
 *   portal.wali:pendaftar   — keluarga yang masuk ATAU pendaftar yang emailnya
 *                             sudah terbukti (hanya untuk formulir pendaftaran).
 *
 * Sesi yang habis dipulihkan dari cookie "ingat perangkat" lebih dulu, baru
 * diarahkan ke halaman masuk bila tak ada.
 */
class PortalWali
{
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $sesi = new SesiWali;
        $wali = SesiWali::wali();

        // Keluarga yang dinonaktifkan staf ditutup seketika, bukan menunggu sesinya habis.
        if ($wali && $wali->status !== 'aktif') {
            $sesi->keluar($request);
            $wali = null;
        }
        if (! $wali && $sesi->pulihkan($request)) {
            $wali = SesiWali::wali();
        }

        if (($wali && SesiWali::peran($request)) || ($mode === 'pendaftar' && SesiWali::emailPendaftar($request))) {
            return $this->tanpaCache($next($request));
        }

        return redirect()->route('portal.masuk');
    }

    /**
     * Halaman portal tak boleh diambil dari cache browser (termasuk bfcache saat
     * tombol kembali HP ditekan): salinan lama halaman Bayar muncul lagi dengan
     * papan PIN masih terbuka untuk tagihan yang sudah lunas. Dengan `no-store`
     * halamannya dimuat ulang dari server, dan server mengalihkan tagihan lunas.
     * Juga menjaga data uang keluarga tak tertinggal di cache perangkat bersama.
     */
    private function tanpaCache(Response $respons): Response
    {
        $respons->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $respons->headers->set('Pragma', 'no-cache');

        return $respons;
    }
}
