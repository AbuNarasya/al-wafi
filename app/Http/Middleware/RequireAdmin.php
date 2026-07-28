<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hanya administrator. Dipakai modul yang wewenangnya setara hak penuh — mis.
 * pengaturan matriks hak akses itu sendiri (matriks tak bisa menjaga dirinya).
 */
class RequireAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        if (! $user || $user->status !== 'aktif' || ! $user->is_admin) {
            abort(403, 'Khusus administrator.');
        }

        return $next($request);
    }
}
