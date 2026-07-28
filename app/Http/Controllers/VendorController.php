<?php

namespace App\Http\Controllers;

use App\Http\Requests\VendorRequest;
use App\Models\Vendor;
use App\Models\VendorType;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VendorController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $vendors = Vendor::query()->with('jenis')
            ->when($q !== '', fn ($query) => $query->where(
                fn ($w) => $w->where('kode_vendor', 'ilike', "%{$q}%")->orWhere('nama_vendor', 'ilike', "%{$q}%"),
            ))
            ->orderBy('kode_vendor')->get();

        return view('vendors.index', compact('vendors', 'q'));
    }

    public function create(): View
    {
        return view('vendors.form', ['vendor' => new Vendor(['status' => 'aktif', 'metode_pembayaran' => 'tunai']), 'jenisOptions' => $this->jenisOptions()]);
    }

    public function store(VendorRequest $request): RedirectResponse
    {
        Vendor::create($request->tersimpan());

        return redirect()->route('vendors.index')->with('status', 'Vendor berhasil ditambahkan.');
    }

    public function edit(Vendor $vendor): View
    {
        return view('vendors.form', ['vendor' => $vendor, 'jenisOptions' => $this->jenisOptions()]);
    }

    public function update(VendorRequest $request, Vendor $vendor): RedirectResponse
    {
        $vendor->update($request->tersimpan());

        return redirect()->route('vendors.index')->with('status', 'Vendor berhasil diperbarui.');
    }

    public function destroy(Vendor $vendor): RedirectResponse
    {
        try {
            $vendor->delete();
        } catch (QueryException $e) {
            return redirect()->route('vendors.index')->with('error', 'Vendor tidak bisa dihapus karena masih dipakai transaksi.');
        }

        return redirect()->route('vendors.index')->with('status', 'Vendor berhasil dihapus.');
    }

    private function jenisOptions(): array
    {
        return VendorType::where('status', 'aktif')->orderBy('kode_jenis_vendor')->get()
            ->mapWithKeys(fn ($t) => [$t->kode_jenis_vendor => "{$t->kode_jenis_vendor} — {$t->nama}"])->all();
    }
}
