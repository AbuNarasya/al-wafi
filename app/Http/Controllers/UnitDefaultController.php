<?php

namespace App\Http\Controllers;

use App\Models\BusinessUnit;
use App\Models\UnitDefault;
use App\Services\Ledger\SumberModul;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * CRUD Default Unit Bisnis per modul asal jurnal. Dipakai postJournal saat
 * pemanggil tidak menentukan unit. sumber_modul divalidasi ke daftar sah.
 */
class UnitDefaultController extends Controller
{
    public function index(): View
    {
        $rows = UnitDefault::with('unit')->orderBy('sumber_modul')->get();

        return view('unit-default.index', compact('rows'));
    }

    public function create(): View
    {
        return view('unit-default.form', [
            'row' => new UnitDefault(),
            'baru' => true,
            'sumberOptions' => $this->sumberOptions(),
            'unitOptions' => $this->unitOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sumber_modul' => ['required', Rule::in(SumberModul::ALL), Rule::unique('unit_defaults', 'sumber_modul')],
            'kode_unit' => ['required', 'string', Rule::exists('business_units', 'kode_unit')],
            'keterangan' => ['nullable', 'string'],
        ]);

        UnitDefault::create($data);

        return redirect()->route('unit_default.index')->with('status', 'Default unit berhasil ditambahkan.');
    }

    public function edit(UnitDefault $unit_default): View
    {
        return view('unit-default.form', [
            'row' => $unit_default,
            'baru' => false,
            'sumberOptions' => $this->sumberOptions(),
            'unitOptions' => $this->unitOptions(),
        ]);
    }

    public function update(Request $request, UnitDefault $unit_default): RedirectResponse
    {
        $data = $request->validate([
            'kode_unit' => ['required', 'string', Rule::exists('business_units', 'kode_unit')],
            'keterangan' => ['nullable', 'string'],
        ]);

        $unit_default->update($data);

        return redirect()->route('unit_default.index')->with('status', 'Default unit berhasil diperbarui.');
    }

    public function destroy(UnitDefault $unit_default): RedirectResponse
    {
        $unit_default->delete();

        return redirect()->route('unit_default.index')->with('status', 'Default unit berhasil dihapus.');
    }

    private function sumberOptions(): array
    {
        return collect(SumberModul::ALL)->mapWithKeys(fn ($s) => [$s => $s])->all();
    }

    private function unitOptions(): array
    {
        return BusinessUnit::where('status', 'aktif')->orderBy('kode_unit')->get()
            ->mapWithKeys(fn ($u) => [$u->kode_unit => "{$u->kode_unit} — {$u->nama_unit}"])->all();
    }
}
