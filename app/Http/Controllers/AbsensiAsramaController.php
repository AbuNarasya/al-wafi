<?php

namespace App\Http\Controllers;

use App\Models\AbsensiAsrama;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Absensi Asrama dari ISE — sisi staf: data masuk, yang tak tercocok dengan santri ERP, dan log kiriman. */
class AbsensiAsramaController extends Controller
{
    public const TAB = ['data' => 'Data masuk', 'tak_tercocok' => 'Tak tercocok', 'log' => 'Log kiriman ISE'];

    public function index(Request $request): View
    {
        $tab = isset(self::TAB[$request->query('tab')]) ? $request->query('tab') : 'data';
        $tanggal = rescue(fn () => Carbon::createFromFormat('Y-m-d', (string) $request->query('tanggal'), 'Asia/Jakarta'), null, false) ?? now('Asia/Jakarta');
        $cari = trim((string) $request->query('q', ''));
        $kategori = $request->query('kategori');

        return view('absensi-asrama.index', [
            'tab' => $tab, 'tanggal' => $tanggal, 'cari' => $cari, 'kategori' => $kategori,
            'aktif' => (string) config('services.ise.token') !== '',
            'rows' => match ($tab) {
                'log' => DB::table('log_kiriman_ise')->orderByDesc('id')->paginate(50)->withQueryString(),
                default => AbsensiAsrama::with('santri:id,nama,nis')
                    ->when($tab === 'tak_tercocok', fn ($q) => $q->whereNull('id_santri'),
                        fn ($q) => $q->whereBetween('waktu', [$tanggal->copy()->startOfDay()->utc(), $tanggal->copy()->endOfDay()->utc()]))
                    ->when($cari !== '', fn ($q) => $q->where(fn ($w) => $w->where('nama_santri', 'ilike', "%{$cari}%")->orWhere('nis', 'ilike', "%{$cari}%")
                        ->orWhereHas('santri', fn ($s) => $s->where('nama', 'ilike', "%{$cari}%"))))
                    ->when($kategori, fn ($q) => $q->where('kategori', $kategori))
                    ->orderByDesc('waktu')->paginate(50)->withQueryString(),
            },
            'takTercocok' => AbsensiAsrama::whereNull('id_santri')->count(),
        ]);
    }
}
