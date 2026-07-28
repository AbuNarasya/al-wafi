<?php

namespace App\Http\Controllers;

use App\Http\Requests\BusinessUnitRequest;
use App\Models\BusinessUnit;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * CRUD Unit Bisnis (dimensi 1 voucher = 1 unit pada level entry jurnal).
 */
class BusinessUnitController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $units = BusinessUnit::query()
            ->when($q !== '', fn ($query) => $query->where(
                fn ($w) => $w->where('kode_unit', 'ilike', "%{$q}%")
                    ->orWhere('nama_unit', 'ilike', "%{$q}%"),
            ))
            ->orderBy('kode_unit')
            ->get();

        return view('business-units.index', compact('units', 'q'));
    }

    public function create(): View
    {
        return view('business-units.form', ['unit' => new BusinessUnit(['status' => 'aktif'])]);
    }

    public function store(BusinessUnitRequest $request): RedirectResponse
    {
        BusinessUnit::create($request->tersimpan());

        return redirect()->route('business_units.index')->with('status', 'Unit bisnis berhasil ditambahkan.');
    }

    public function edit(BusinessUnit $business_unit): View
    {
        return view('business-units.form', ['unit' => $business_unit]);
    }

    public function update(BusinessUnitRequest $request, BusinessUnit $business_unit): RedirectResponse
    {
        $business_unit->update($request->tersimpan());

        return redirect()->route('business_units.index')->with('status', 'Unit bisnis berhasil diperbarui.');
    }

    public function destroy(BusinessUnit $business_unit): RedirectResponse
    {
        try {
            $business_unit->delete();
        } catch (QueryException $e) {
            return redirect()->route('business_units.index')
                ->with('error', 'Unit bisnis tidak bisa dihapus karena masih dipakai jurnal / default unit.');
        }

        return redirect()->route('business_units.index')->with('status', 'Unit bisnis berhasil dihapus.');
    }
}
