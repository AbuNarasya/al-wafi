<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\CoaDetail;
use App\Services\Modules\PeriodCloseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Tutup Buku Periode (port period-close dev): tutup/buka bulan + tutup/buka
 * buku tahunan.
 */
class PeriodCloseController extends Controller
{
    public function __construct(private PeriodCloseService $service = new PeriodCloseService) {}

    private function tahun(Request $request): int
    {
        $t = (int) $request->query('tahun', now()->format('Y'));

        return ($t >= 2000 && $t <= 2100) ? $t : (int) now()->format('Y');
    }

    public function index(Request $request): View
    {
        $tahun = $this->tahun($request);

        return view('period-close.index', [
            'status' => $this->service->statusTahun($tahun),
            'tahun' => $tahun,
            'coaOptions' => ['' => '— pilih akun laba ditahan —'] + CoaDetail::where('status', 'aktif')
                ->where('kode_coa', 'like', '3%')->orderBy('kode_coa')->get()
                ->mapWithKeys(fn ($c) => [$c->kode_coa => "{$c->kode_coa} — {$c->nama_coa}"])->all(),
        ]);
    }

    public function tutupBulan(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'tahun' => ['required', 'integer'], 'bulan' => ['required', 'integer', 'between:1,12'],
            'keterangan' => ['nullable', 'string', 'max:255'],
        ]);
        try {
            $this->service->tutupBulan($d['tahun'], $d['bulan'], $request->user()->id_pengguna, $request->user()->nama, $d['keterangan'] ?? null);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('period_close.index', ['tahun' => $d['tahun']])->with('status', "Bulan {$d['bulan']}/{$d['tahun']} ditutup.");
    }

    public function bukaBulan(Request $request): RedirectResponse
    {
        $d = $request->validate(['tahun' => ['required', 'integer'], 'bulan' => ['required', 'integer', 'between:1,12']]);
        try {
            $this->service->bukaBulan($d['tahun'], $d['bulan'], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('period_close.index', ['tahun' => $d['tahun']])->with('status', "Bulan {$d['bulan']}/{$d['tahun']} dibuka kembali.");
    }

    public function tutupTahun(Request $request): RedirectResponse
    {
        $d = $request->validate(['tahun' => ['required', 'integer'], 'kode_coa_laba_ditahan' => ['required', 'string', 'exists:coa_detail,kode_coa']]);
        try {
            $r = $this->service->tutupTahun($d['tahun'], $d['kode_coa_laba_ditahan'], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('period_close.index', ['tahun' => $d['tahun']])->with('status', "Tutup buku {$d['tahun']} selesai ({$r['referensi']}, laba/rugi @rp {$r['laba_rugi']}).");
    }

    public function bukaTahun(Request $request): RedirectResponse
    {
        $d = $request->validate(['tahun' => ['required', 'integer']]);
        try {
            $this->service->bukaTahun($d['tahun'], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('period_close.index', ['tahun' => $d['tahun']])->with('status', "Tutup buku tahunan {$d['tahun']} dibuka.");
    }
}
