<?php

namespace App\Http\Controllers;

use App\Support\Navigation;
use App\Support\TugasSaya;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * BERANDA (4 Nov 2026) — halaman pertama sesudah login bagi SEMUA pengguna, tanpa hak khusus.
 *
 * Dulu login mendarat di Dashboard, yang menolak (403) pengguna tanpa satu pun hak dashboard —
 * petugas laundry, perizinan, dst. terkunci di layar galat. Beranda hanya menampilkan apa yang
 * memang boleh dilihat orangnya: tugas yang menunggu, pintasan menu (sumbernya sama dengan
 * sidebar), dan tombol Dashboard bila ia berhak.
 */
class BerandaController extends Controller
{
    public function index(Request $request): View
    {
        // Pemicu cadangan rilis Batch Tagihan — dulu hanya di Dashboard, halaman yang paling
        // pasti dibuka tiap hari. Kini itu Beranda; di Dashboard tetap dipertahankan.
        (new \App\Services\Modules\BatchTagihanService)->pemicuCadangan();

        $pohon = Navigation::tree();
        $dashboard = collect($pohon)->firstWhere('group', '');
        $punyaDashboard = collect($dashboard['subs'] ?? [])->flatMap(fn ($s) => $s['items'])->contains('url', '/dashboard');

        return view('beranda.index', [
            'pengguna' => $request->user(),
            'tugas' => TugasSaya::daftar(),
            // Grup tanpa header (Beranda & Dashboard) tak ikut jadi kartu menu.
            'grup' => array_values(array_filter($pohon, fn ($g) => $g['group'] !== '')),
            'punyaDashboard' => $punyaDashboard,
        ]);
    }
}
