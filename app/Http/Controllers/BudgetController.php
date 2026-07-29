<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\Bagian;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Services\Ledger\AnggaranPeriode;
use App\Services\Modules\BudgetLockService;
use App\Services\Modules\BudgetRealisasiService;
use App\Services\Modules\BudgetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Modul ANGGARAN: Input Anggaran (grid per TA×bagian×unit, simpan/kunci admin)
 * & Realisasi Anggaran (review anggaran vs realisasi, gerbang bertingkat).
 * Jalur non-admin (ajukan → rantai persetujuan) ada di BudgetPengajuanController.
 */
class BudgetController extends Controller
{
    public function __construct(
        private BudgetService $budget = new BudgetService,
        private BudgetLockService $lock = new BudgetLockService,
    ) {}

    private function tahunValid(mixed $raw): int
    {
        $n = (int) $raw;

        return ($n >= 2000 && $n <= 2100) ? $n : (int) now()->format('Y');
    }

    /** Halaman Input Anggaran (grid). */
    public function index(Request $request): View
    {
        $user = $request->user();
        $isAdmin = (bool) $user->is_admin;

        $tahun = $this->tahunValid($request->query('tahun', now()->format('Y')));
        $kodeUnit = trim((string) $request->query('kode_unit', ''));
        // Non-admin dikunci ke bagiannya sendiri.
        $kodeBagian = $isAdmin
            ? trim((string) $request->query('kode_bagian', ''))
            : (string) ($user->kode_bagian ?? '');

        $grid = $kodeBagian !== '' ? $this->budget->grid($tahun, $kodeBagian, $kodeUnit ?: null) : null;

        return view('budget.input', [
            'isAdmin' => $isAdmin,
            'tahun' => $tahun,
            'kodeUnit' => $kodeUnit,
            'kodeBagian' => $kodeBagian,
            'grid' => $grid,
            'bagian' => Bagian::orderBy('kode_bagian')->get(['kode_bagian', 'nama_bagian']),
            'units' => BusinessUnit::where('status', 'aktif')->orderBy('kode_unit')->get(['kode_unit', 'nama_unit']),
            'bebanAkun' => CoaDetail::where('kode_coa', 'like', '5%')
                ->orderBy('kode_coa')->get(['kode_coa', 'nama_coa']),
            'lockedYears' => $this->lock->list()->pluck('tahun')->all(),
            'labelTa' => AnggaranPeriode::labelTahunAnggaran($tahun, AnggaranPeriode::bulanAwalAnggaran()),
        ]);
    }

    /** Simpan langsung (jalur admin). Payload JSON dari grid Alpine. */
    public function save(Request $request): RedirectResponse
    {
        $data = json_decode((string) $request->input('payload'), true);
        if (! is_array($data)) {
            return back()->with('error', 'Data anggaran tidak valid.');
        }
        $tahun = $this->tahunValid($data['tahun'] ?? null);
        $kodeBagian = trim((string) ($data['kode_bagian'] ?? ''));
        $kodeUnit = trim((string) ($data['kode_unit'] ?? ''));

        try {
            $this->budget->save([
                'tahun' => $tahun,
                'kode_bagian' => $kodeBagian,
                'kode_unit' => $kodeUnit ?: null,
                'items' => array_map(fn ($it) => [
                    'kode_coa' => (string) ($it['kode_coa'] ?? ''),
                    'bulan' => (int) ($it['bulan'] ?? 0),
                    'nominal' => (string) ($it['nominal'] ?? '0'),
                ], is_array($data['items'] ?? null) ? $data['items'] : []),
            ]);
        } catch (AppException $e) {
            return $this->kembali($tahun, $kodeBagian, $kodeUnit)->with('error', $e->getMessage());
        }

        return $this->kembali($tahun, $kodeBagian, $kodeUnit)->with('status', 'Anggaran tersimpan langsung (jalur admin).');
    }

    /** Kunci TA (admin). */
    public function lock(Request $request): RedirectResponse
    {
        $tahun = $this->tahunValid($request->input('tahun'));
        $this->lock->kunci($tahun, $request->user()->id_pengguna, $request->input('catatan'));

        return $this->kembali($tahun, (string) $request->input('kode_bagian', ''), (string) $request->input('kode_unit', ''))
            ->with('status', "Anggaran TA {$tahun} dikunci.");
    }

    /** Buka kunci TA (admin). */
    public function unlock(Request $request, int $tahun): RedirectResponse
    {
        $this->lock->buka($tahun);

        return $this->kembali($tahun, (string) $request->input('kode_bagian', ''), (string) $request->input('kode_unit', ''))
            ->with('status', "Kunci anggaran TA {$tahun} dibuka.");
    }

    /** Halaman Realisasi Anggaran (gerbang di service). */
    public function realisasi(Request $request): View
    {
        $tahun = $this->tahunValid($request->query('tahun', now()->format('Y')));
        $kodeUnit = trim((string) $request->query('kode_unit', ''));
        $kodeBagian = trim((string) $request->query('kode_bagian', ''));

        $data = null;
        $error = null;
        try {
            $data = app(BudgetRealisasiService::class)->review(
                $request->user()->id_pengguna,
                $tahun,
                $kodeBagian ?: null,
                $kodeUnit ?: null,
            );
        } catch (AppException $e) {
            $error = $e->getMessage();
        }

        return view('budget.realisasi', [
            'tahun' => $tahun,
            'kodeUnit' => $kodeUnit,
            'kodeBagian' => $kodeBagian,
            'data' => $data,
            'error' => $error,
            'units' => BusinessUnit::where('status', 'aktif')->orderBy('kode_unit')->get(['kode_unit', 'nama_unit']),
        ]);
    }

    private function kembali(int $tahun, string $kodeBagian, string $kodeUnit): RedirectResponse
    {
        return redirect()->route('budget.index', array_filter([
            'tahun' => $tahun,
            'kode_bagian' => $kodeBagian ?: null,
            'kode_unit' => $kodeUnit ?: null,
        ]));
    }
}
