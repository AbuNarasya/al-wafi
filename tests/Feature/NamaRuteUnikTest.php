<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 21 Okt 2026: rute baru bernama `portal.keluar` diam-diam menimpa rute logout portal yang bernama sama.
 * Test lain tetap lulus (nama terakhir menang), tetapi `artisan optimize` di server menolak membuat cache
 * rute → seluruh instans 500. Nama rute wajib unik.
 */
class NamaRuteUnikTest extends TestCase
{
    public function test_tak_ada_nama_rute_kembar(): void
    {
        // Nama berakhiran "." = rute tanpa nama di dalam grup berawalan nama (mis. "portal.") — bukan nama sungguhan.
        $nama = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->getName())->filter(fn ($n) => $n && ! str_ends_with($n, '.'));
        $kembar = $nama->countBy()->filter(fn ($n) => $n > 1)->keys()->all();

        $this->assertSame([], $kembar, 'Nama rute kembar: '.implode(', ', $kembar));
    }
}
