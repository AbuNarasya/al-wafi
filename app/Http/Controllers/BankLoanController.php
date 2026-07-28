<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Http\Requests\BankLoanRequest;
use App\Models\BankAccount;
use App\Models\BankLoan;
use App\Models\CoaDetail;
use App\Services\Modules\BankLoanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Pembiayaan Bank (syariah). Controller tipis → BankLoanService. Pencairan
 * opsional memposting jurnal Debit Kas/Bank, Kredit hutang pembiayaan.
 */
class BankLoanController extends Controller
{
    public function __construct(private readonly BankLoanService $service) {}

    public function index(): View
    {
        return view('bank-loans.index', ['rows' => $this->service->list()]);
    }

    public function create(): View
    {
        return view('bank-loans.create', $this->opsi());
    }

    public function store(BankLoanRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['posting_pencairan'] = $request->boolean('posting_pencairan');

        try {
            $loan = $this->service->create($data, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('bank_loans.show', $loan->id)->with('status', 'Pembiayaan berhasil dicatat.');
    }

    public function show(BankLoan $bank_loan): View
    {
        return view('bank-loans.show', ['loan' => $this->service->get($bank_loan->id)]);
    }

    public function void(Request $request, BankLoan $bank_loan): RedirectResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'max:255']]);

        try {
            $this->service->void($bank_loan->id, $data['alasan'], $request->user()->id_pengguna, $request->user()->nama);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bank_loans.index')->with('status', 'Pembiayaan berhasil di-void.');
    }

    private function opsi(): array
    {
        return [
            'akadOptions' => [
                'murabahah' => 'Murabahah', 'ijarah' => 'Ijarah',
                'musyarakah_mutanaqishah' => 'Musyarakah Mutanaqishah', 'qardh' => 'Qardh',
                'istishna' => 'Istishna', 'lainnya' => 'Lainnya',
            ],
            'coaOptions' => CoaDetail::where('status', 'aktif')->orderBy('kode_coa')->get()
                ->mapWithKeys(fn ($c) => [$c->kode_coa => "{$c->kode_coa} — {$c->nama_coa}"])->all(),
            'rekeningOptions' => BankAccount::where('status', 'aktif')->with('coa')->orderBy('kode_coa')->get()
                ->mapWithKeys(fn ($r) => [$r->kode_coa => "{$r->nama_rekening} ({$r->kode_coa})"])->all(),
        ];
    }
}
