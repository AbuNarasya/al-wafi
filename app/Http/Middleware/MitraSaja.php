<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Layar /mitra hanya untuk akun mitra (staf memakai layar Mitra Kantin). */
class MitraSaja
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if($request->user()?->id_mitra === null, 403, 'Layar ini khusus akun mitra kantin.');

        return $next($request);
    }
}
