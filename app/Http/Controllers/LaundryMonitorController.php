<?php

namespace App\Http\Controllers;

use App\Models\Kelas;
use App\Services\Modules\MonitorLaundryService;
use App\Support\BagianLaundry;
use App\Support\Referensi;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Monitor Kelebihan Laundry (paket 2, 3 Nov 2026) — pemakaian vs kuota, tagihan kelebihan, tunggakan. Lihat MonitorLaundryService. */
class LaundryMonitorController extends Controller
{
    public const TAMPIL = ['pemakaian' => 'Pemakaian bulan ini', 'tagihan' => 'Tagihan kelebihan', 'tunggakan' => 'Tunggakan'];

    public function index(Request $request, MonitorLaundryService $svc): View
    {
        $tampil = isset(self::TAMPIL[$request->query('tampil')]) ? $request->query('tampil') : 'pemakaian';
        $bulan = fn ($v) => preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $v) ? $v : null;
        $f = [
            'bagian' => isset(BagianLaundry::BAGIAN[$request->query('bagian')]) ? $request->query('bagian') : null,
            'jenjang' => $request->query('jenjang') ?: null, 'kelas' => $request->query('kelas') ?: null,
            'q' => trim((string) $request->query('q', '')),
            'periode' => $bulan($request->query('periode')) ?? now()->format('Y-m'),
            'saring' => in_array($request->query('saring'), ['over', 'dekat'], true) ? $request->query('saring') : null,
            'status' => in_array($request->query('status'), ['lunas', 'belum'], true) ? $request->query('status') : null,
            'dari' => $bulan($request->query('dari')), 'sampai' => $bulan($request->query('sampai')),
        ];

        return view('laundry.monitor', [
            'tampil' => $tampil, 'f' => $f,
            'rows' => match ($tampil) {
                'pemakaian' => $svc->pemakaian($f['periode'], $f),
                'tagihan' => $svc->tagihan($f),
                'tunggakan' => $svc->tunggakan($f),
            },
            'opsiJenjang' => Referensi::jenjang(),
            'opsiKelas' => Kelas::where('status', 'aktif')->orderBy('kode_jenjang')->orderBy('tingkat')->orderBy('nama')->pluck('nama', 'id')->all(),
        ]);
    }
}
