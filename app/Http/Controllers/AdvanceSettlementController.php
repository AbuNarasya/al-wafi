<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Http\Requests\AdvanceSettlementRequest;
use App\Models\Bagian;
use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Services\Modules\AdvanceSettlementService;
use App\Services\Modules\OperationalAdvanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Penyelesaian Uang Muka (standalone). Controller tipis → AdvanceSettlementService.
 * Jurnal: Kredit akun uang muka, Debit akun realisasi; selisih via Kas/Bank.
 */
class AdvanceSettlementController extends Controller
{
    public function __construct(
        private readonly AdvanceSettlementService $service,
        private readonly OperationalAdvanceService $advances,
    ) {}

    public function index(): View
    {
        return view('advance-settlement.index', ['rows' => $this->service->list()]);
    }

    public function create(): View
    {
        return view('advance-settlement.create', [
            'outstanding' => $this->advances->listOutstanding(),
            ...$this->opsi(),
        ]);
    }

    public function store(AdvanceSettlementRequest $request): RedirectResponse
    {
        try {
            $this->service->create($request->validated(), $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('advance_settlement.index')->with('status', 'Penyelesaian uang muka berhasil diposting.');
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
