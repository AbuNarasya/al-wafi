<?php

namespace App\Http\Controllers;

use App\Http\Requests\BankAccountRequest;
use App\Models\BankAccount;
use App\Models\CoaDetail;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * CRUD Kas & Rekening. Satu rekening = satu akun COA (kode_coa PK sekaligus FK
 * ke coa_detail). Dipakai modul transaksi & rekonsiliasi bank.
 */
class BankAccountController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $rekening = BankAccount::query()
            ->with('coa')
            ->when($q !== '', fn ($query) => $query->where(
                fn ($w) => $w->where('kode_coa', 'ilike', "%{$q}%")->orWhere('nama_rekening', 'ilike', "%{$q}%"),
            ))
            ->orderBy('kode_coa')
            ->get();

        return view('bank-accounts.index', compact('rekening', 'q'));
    }

    public function create(): View
    {
        return view('bank-accounts.form', ['rek' => new BankAccount(['status' => 'aktif', 'jenis_rekening' => 'bank']), 'coaOptions' => $this->coaOptions()]);
    }

    public function store(BankAccountRequest $request): RedirectResponse
    {
        BankAccount::create($request->tersimpan());

        return redirect()->route('bank_accounts.index')->with('status', 'Rekening berhasil ditambahkan.');
    }

    public function edit(BankAccount $bank_account): View
    {
        return view('bank-accounts.form', ['rek' => $bank_account, 'coaOptions' => $this->coaOptions()]);
    }

    public function update(BankAccountRequest $request, BankAccount $bank_account): RedirectResponse
    {
        $bank_account->update($request->tersimpan());

        return redirect()->route('bank_accounts.index')->with('status', 'Rekening berhasil diperbarui.');
    }

    public function destroy(BankAccount $bank_account): RedirectResponse
    {
        try {
            $bank_account->delete();
        } catch (QueryException $e) {
            return redirect()->route('bank_accounts.index')->with('error', 'Rekening tidak bisa dihapus karena masih dipakai transaksi.');
        }

        return redirect()->route('bank_accounts.index')->with('status', 'Rekening berhasil dihapus.');
    }

    /** Akun kas/bank yang lazim: kelompok 1 (aset), status aktif. */
    private function coaOptions(): array
    {
        return CoaDetail::query()->where('status', 'aktif')->orderBy('kode_coa')->get()
            ->mapWithKeys(fn ($c) => [$c->kode_coa => "{$c->kode_coa} — {$c->nama_coa}"])->all();
    }
}
