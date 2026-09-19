<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\CoaDetail;
use App\Services\Modules\BukaPeriodeService;
use App\Services\Modules\PeriodCloseService;
use App\Support\Akses;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Tutup Buku Periode: menutup bulan & tahun, serta PERMOHONAN membukanya
 * kembali.
 *
 * Tombol "buka" yang dulu langsung bekerja sudah tidak ada. Periode yang sudah
 * ditutup hanya bisa dibuka lewat permohonan beralasan dari admin keuangan yang
 * diputuskan direktur keuangan — lihat [[BukaPeriodeService]].
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

        $bukaPeriode = new BukaPeriodeService;

        return view('period-close.index', [
            'status' => $this->service->statusTahun($tahun),
            'tahun' => $tahun,
            'permohonan' => $bukaPeriode->daftar(),
            'bolehAjukan' => Akses::boleh(BukaPeriodeService::MODUL, 'buat'),
            'bolehPutuskan' => Akses::boleh(BukaPeriodeService::MODUL, 'ubah'),
            'idSaya' => $request->user()->id_pengguna,
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

    /**
     * Ajukan pembukaan periode. Menggantikan tombol "buka" yang dulu langsung
     * bekerja — kini ia hanya melahirkan permohonan beralasan yang menunggu
     * keputusan direktur keuangan.
     */
    public function ajukanBuka(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'lingkup' => ['required', 'in:bulan,tahun'],
            'tahun' => ['required', 'integer'],
            'bulan' => ['required_if:lingkup,bulan', 'nullable', 'integer', 'between:1,12'],
            'alasan' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $p = (new BukaPeriodeService)->ajukan($d, $request->user());
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('period_close.index', ['tahun' => $d['tahun']])
            ->with('status', "Permohonan pembukaan {$p->labelPeriode()} diajukan. Menunggu keputusan direktur keuangan.");
    }

    public function setujuiBuka(Request $request, int $id): RedirectResponse
    {
        $d = $request->validate(['catatan_keputusan' => ['nullable', 'string', 'max:1000']]);

        try {
            $p = (new BukaPeriodeService)->setujui($id, $request->user(), $d['catatan_keputusan'] ?? null);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('period_close.index', ['tahun' => $p->tahun])
            ->with('status', "Permohonan disetujui — {$p->labelPeriode()} dibuka kembali.");
    }

    public function tolakBuka(Request $request, int $id): RedirectResponse
    {
        $d = $request->validate(['catatan_keputusan' => ['required', 'string', 'max:1000']]);

        try {
            $p = (new BukaPeriodeService)->tolak($id, $request->user(), $d['catatan_keputusan']);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('period_close.index', ['tahun' => $p->tahun])
            ->with('status', "Permohonan pembukaan {$p->labelPeriode()} ditolak.");
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
}
