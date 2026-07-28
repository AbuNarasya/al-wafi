<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'hakakses' => \App\Http\Middleware\HakAksesModul::class,
        ]);

        // Di belakang proxy/tunnel (Render, Cloudflare Tunnel, Nginx) TLS diputus
        // di depan, sehingga PHP melihat koneksi HTTP biasa. Tanpa ini Laravel
        // membangun URL http:// di halaman https:// → aset diblokir browser
        // sebagai mixed content dan redirect login berputar.
        // Aman dipakai di localhost: header X-Forwarded-* hanya ada bila memang
        // ada proxy di depannya.
        $middleware->trustProxies(at: '*');

        // Rute web belum ditemukan / tamu diarahkan ke halaman login.
        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
