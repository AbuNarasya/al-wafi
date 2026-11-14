<?php

namespace App\Http\Controllers;

use App\Models\AbsensiAsrama;
use App\Models\Santri;
use App\Services\Modules\AbsensiAsramaService;
use App\Services\PortalWali\SesiWali;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/** Menu KEHADIRAN ASRAMA di portal wali (4 Nov 2026) — dari kiriman ISE; ringkasan 7/30 hari & riwayat per hari. */
class PortalKehadiranController extends Controller
{
    public function index(Request $request, AbsensiAsramaService $svc): View|\Illuminate\Http\RedirectResponse
    {
        // Saat menu dari API Histori ISE menyala, halaman lama (juga tujuan kabar "tidak hadir") pindah ke Absensi → Asrama.
        if (\App\Services\PortalWali\HistoriIse::aktifUntuk(SesiWali::wali())) {
            return redirect()->route('portal.absensi', array_filter(['tab' => 'asrama', 'anak' => $request->query('anak')]));
        }
        $anak = Santri::where('id_wali', SesiWali::wali()->id)->where('status', 'aktif')->orderBy('nama')->get(['id', 'nama', 'id_wali', 'status']);
        $pilih = $anak->firstWhere('id', (int) $request->query('anak')) ?? $anak->first();
        $tanggal = rescue(fn () => Carbon::createFromFormat('Y-m-d', (string) $request->query('tanggal'), 'Asia/Jakarta'), null, false)
            ?? now('Asia/Jakarta');
        $awal = $tanggal->copy()->startOfDay()->utc();
        $akhir = $tanggal->copy()->endOfDay()->utc();
        $hariIni = now('Asia/Jakarta');

        return view('portal.kehadiran', [
            'anak' => $anak, 'pilih' => $pilih, 'tanggal' => $tanggal,
            'harian' => $pilih ? AbsensiAsrama::where('id_santri', $pilih->id)->whereBetween('waktu', [$awal, $akhir])->orderBy('waktu')->get() : collect(),
            'izin' => $pilih ? AbsensiAsramaService::izinDalam($pilih->id, $awal, $akhir) : collect(),
            'ringkas7' => $pilih ? $svc->ringkasan($pilih, $hariIni->copy()->subDays(6)->startOfDay()->utc(), $hariIni->copy()->endOfDay()->utc()) : collect(),
            'ringkas30' => $pilih ? $svc->ringkasan($pilih, $hariIni->copy()->subDays(29)->startOfDay()->utc(), $hariIni->copy()->endOfDay()->utc()) : collect(),
        ]);
    }
}
