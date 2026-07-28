<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Http\Requests\OperationalAdvanceRequest;
use App\Models\Bagian;
use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\OperationalAdvance;
use App\Services\Modules\OperationalAdvanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Uang Muka Operasional. Controller tipis → OperationalAdvanceService.
 * Jurnal: Debit akun uang muka; Kredit kas/bank. Penyelesaian di modul terpisah.
 */
class OperationalAdvanceController extends Controller
{
    public function __construct(private readonly OperationalAdvanceService $service) {}

    public function index(): View
    {
        return view('operational-advance.index', ['rows' => $this->service->list()]);
    }

    public function create(): View
    {
        return view('operational-advance.create', $this->opsi());
    }

    public function store(OperationalAdvanceRequest $request): RedirectResponse
    {
        try {
            $this->service->create($request->validated(), $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('operational_advance.index')->with('status', 'Uang muka operasional berhasil diposting.');
    }

    public function void(Request $request, OperationalAdvance $operational_advance): RedirectResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'max:255']]);

        try {
            $this->service->void($operational_advance->id, $data['alasan'], $request->user()->id_pengguna, $request->user()->nama);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('operational_advance.index')->with('status', 'Uang muka operasional berhasil di-void.');
    }

    private function opsi(): array
    {
        return [
            'coaOptions' => ['' => '— pilih —'] + CoaDetail::where('status', 'aktif')->orderBy('kode_coa')->get()
                ->mapWithKeys(fn ($c) => [$c->kode_coa => "{$c->kode_coa} — {$c->nama_coa}"])->all(),
            'rekeningOptions' => ['' => '— pilih —'] + BankAccount::where('status', 'aktif')->with('coa')->orderBy('kode_coa')->get()
                ->mapWithKeys(fn ($r) => [$r->kode_coa => "{$r->nama_rekening} ({$r->kode_coa})"])->all(),
            'unitOptions' => ['' => '— Default modul —'] + BusinessUnit::where('status', 'aktif')->orderBy('kode_unit')->get()
                ->mapWithKeys(fn ($u) => [$u->kode_unit => "{$u->kode_unit} — {$u->nama_unit}"])->all(),
            'bagianOptions' => ['' => '— (opsional) —'] + Bagian::where('status', 'aktif')->orderBy('kode_bagian')->get()
                ->mapWithKeys(fn ($b) => [$b->kode_bagian => "{$b->kode_bagian} — {$b->nama_bagian}"])->all(),
        ];
    }
}
