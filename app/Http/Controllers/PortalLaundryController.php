<?php

namespace App\Http\Controllers;

use App\Models\Laundry;
use App\Models\Santri;
use App\Models\TagihanLaundry;
use App\Services\Modules\TagihanLaundryService;
use App\Services\PortalWali\SesiWali;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Menu LAUNDRY di portal wali (tahap 3, 26 Okt 2026): kuota bulan ini, cucian yang sedang berjalan beserta
 * langkahnya, riwayat (dengan daftar pakaian & yang kurang), dan tagihan kelebihan per bulan. Per anak aktif.
 */
class PortalLaundryController extends Controller
{
    public function index(Request $request, TagihanLaundryService $svc): View
    {
        $anak = Santri::with('kelas:id,nama')->where('id_wali', SesiWali::wali()->id)->where('status', 'aktif')
            ->orderBy('nama')->get(['id', 'nama', 'nis', 'kode_jenjang', 'jenis_kelamin', 'id_kelas', 'id_wali', 'status']);
        $pilih = $anak->firstWhere('id', (int) $request->query('anak')) ?? $anak->first();

        return view('portal.laundry', [
            'anak' => $anak, 'pilih' => $pilih,
            'kuota' => $pilih ? $svc->ringkas($pilih) : null,
            // Paket 3: cucian bersih tertahan karena tunggakan kelebihan laundry lewat jatuh tempo.
            'tertahan' => $pilih ? (new \App\Services\Modules\MonitorLaundryService)->tertahan($pilih) : null,
            'berjalan' => $pilih ? Laundry::with('item')->where('id_santri', $pilih->id)->whereIn('status', Laundry::BERJALAN)
                ->orderBy('diterima_pada')->get() : collect(),
            'riwayat' => $pilih ? Laundry::with('item')->where('id_santri', $pilih->id)->whereIn('status', ['diserahkan', 'dibatalkan'])
                ->orderByDesc('diterima_pada')->paginate(15)->withQueryString() : null,
            'tagihan' => $pilih ? TagihanLaundry::with('tagihan:id,status,sisa')->where('id_santri', $pilih->id)->where('status', 'terbit')
                ->orderByDesc('periode')->limit(12)->get() : collect(),
        ]);
    }
}
