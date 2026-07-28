<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Http\Requests\BankReconciliationRequest;
use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Models\CoaDetail;
use App\Services\Modules\BankReconciliationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Rekonsiliasi Bank (workflow: draft → tandai item cleared / penyesuaian →
 * finalize). Controller tipis → BankReconciliationService (return array).
 */
class BankReconciliationController extends Controller
{
    public function __construct(private readonly BankReconciliationService $service) {}

    public function index(): View
    {
        $rows = BankReconciliation::query()->orderByDesc('id')->get();
        $namaRek = BankAccount::pluck('nama_rekening', 'kode_coa');

        return view('bank-reconciliation.index', compact('rows', 'namaRek'));
    }

    public function create(): View
    {
        return view('bank-reconciliation.create', [
            'rekeningOptions' => ['' => '— pilih rekening —'] + BankAccount::where('status', 'aktif')->orderBy('kode_coa')->get()
                ->mapWithKeys(fn ($r) => [$r->kode_coa => "{$r->nama_rekening} ({$r->kode_coa})"])->all(),
        ]);
    }

    public function store(BankReconciliationRequest $request): RedirectResponse
    {
        try {
            $rec = $this->service->create($request->validated(), $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('bank_reconciliation.show', $rec['id'])->with('status', 'Draft rekonsiliasi dibuat.');
    }

    public function show(int $id): View
    {
        try {
            $data = $this->service->get($id);
        } catch (AppException $e) {
            abort(404);
        }

        $coaOptions = ['' => '— pilih akun lawan —'] + CoaDetail::where('status', 'aktif')->orderBy('kode_coa')->get()
            ->mapWithKeys(fn ($c) => [$c->kode_coa => "{$c->kode_coa} — {$c->nama_coa}"])->all();

        return view('bank-reconciliation.show', compact('data', 'coaOptions'));
    }

    public function toggleItem(Request $request, int $id, int $itemId): RedirectResponse
    {
        try {
            $this->service->toggleItem($id, $itemId, $request->boolean('cleared'));
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bank_reconciliation.show', $id);
    }

    public function adjustment(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'kode_coa_lawan' => ['required', 'string', 'exists:coa_detail,kode_coa'],
            'nominal' => ['required', 'numeric', 'gt:0'],
            'arah' => ['required', Rule::in(['tambah', 'kurang'])],
            'keterangan' => ['nullable', 'string'],
        ]);

        try {
            $this->service->adjustment($id, $data, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bank_reconciliation.show', $id)->with('status', 'Penyesuaian ditambahkan.');
    }

    public function finalize(int $id): RedirectResponse
    {
        try {
            $this->service->finalize($id);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bank_reconciliation.show', $id)->with('status', 'Rekonsiliasi diselesaikan.');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        try {
            $this->service->remove($id, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bank_reconciliation.index')->with('status', 'Draft rekonsiliasi dihapus.');
    }
}
