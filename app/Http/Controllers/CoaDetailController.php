<?php

namespace App\Http\Controllers;

use App\Http\Requests\CoaDetailRequest;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * CRUD Chart of Account (akun detail / level 4). jenis_saldo menentukan sisi
 * normal akun. Dirujuk journal_lines, opening_balances, bank_accounts.
 */
class CoaDetailController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $akun = CoaDetail::query()
            ->with('grup')
            ->when($q !== '', fn ($query) => $query->where(
                fn ($w) => $w->where('kode_coa', 'ilike', "%{$q}%")->orWhere('nama_coa', 'ilike', "%{$q}%"),
            ))
            ->orderBy('kode_coa')
            ->get();

        return view('coa-detail.index', compact('akun', 'q'));
    }

    public function create(): View
    {
        return view('coa-detail.form', ['akun' => new CoaDetail(['status' => 'aktif']), 'grupOptions' => $this->grupOptions()]);
    }

    public function store(CoaDetailRequest $request): RedirectResponse
    {
        CoaDetail::create($request->tersimpan());

        return redirect()->route('coa_detail.index')->with('status', 'Akun berhasil ditambahkan.');
    }

    public function edit(CoaDetail $coa_detail): View
    {
        return view('coa-detail.form', ['akun' => $coa_detail, 'grupOptions' => $this->grupOptions()]);
    }

    public function update(CoaDetailRequest $request, CoaDetail $coa_detail): RedirectResponse
    {
        $coa_detail->update($request->tersimpan());

        return redirect()->route('coa_detail.index')->with('status', 'Akun berhasil diperbarui.');
    }

    public function destroy(CoaDetail $coa_detail): RedirectResponse
    {
        try {
            $coa_detail->delete();
        } catch (QueryException $e) {
            return redirect()->route('coa_detail.index')
                ->with('error', 'Akun tidak bisa dihapus karena sudah dipakai jurnal / saldo awal / rekening. Nonaktifkan saja.');
        }

        return redirect()->route('coa_detail.index')->with('status', 'Akun berhasil dihapus.');
    }

    /** Hanya grup daun (level 3) yang lazim menampung akun detail. */
    private function grupOptions(): array
    {
        return CoaGroup::query()->orderBy('kode_grup')->get()
            ->mapWithKeys(fn ($g) => [$g->kode_grup => "{$g->kode_grup} — {$g->nama_grup} (L{$g->level})"])->all();
    }
}
