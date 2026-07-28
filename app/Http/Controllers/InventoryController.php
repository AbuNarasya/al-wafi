<?php

namespace App\Http\Controllers;

use App\Models\CoaDetail;
use App\Models\Inventory;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Master Persediaan (port inventory module dev). CRUD + Mutasi Stok manual
 * (masuk/keluar, tanpa jurnal — stok saat ini = stok_masuk − stok_keluar).
 * Mutasi berjurnal terjadi lewat Kas Masuk/Keluar/Invoice (InventoryMovement).
 */
class InventoryController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $rows = Inventory::query()
            ->when($q !== '', fn ($query) => $query->where(
                fn ($w) => $w->where('kode_persediaan', 'ilike', "%{$q}%")->orWhere('nama_persediaan', 'ilike', "%{$q}%"),
            ))
            ->orderBy('kode_persediaan')->get();

        return view('inventory.index', compact('rows', 'q'));
    }

    public function create(): View
    {
        return view('inventory.form', ['item' => new Inventory(['status' => 'aktif', 'stok_masuk' => 0, 'stok_keluar' => 0]), 'coaOptions' => $this->coaOptions()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validasi($request, true);
        $data['kode_persediaan'] = $this->nextKode();
        Inventory::create($data);

        return redirect()->route('inventory.index')->with('status', "Persediaan {$data['kode_persediaan']} ditambahkan.");
    }

    public function edit(Inventory $inventory): View
    {
        return view('inventory.form', ['item' => $inventory, 'coaOptions' => $this->coaOptions()]);
    }

    public function update(Request $request, Inventory $inventory): RedirectResponse
    {
        // Stok TIDAK diubah di sini (pakai Mutasi Stok).
        $data = $this->validasi($request, false);
        unset($data['stok_masuk'], $data['stok_keluar']);
        $inventory->update($data);

        return redirect()->route('inventory.index')->with('status', 'Persediaan diperbarui.');
    }

    public function destroy(Inventory $inventory): RedirectResponse
    {
        try {
            $inventory->delete();
        } catch (QueryException) {
            return back()->with('error', 'Persediaan tidak dapat dihapus karena masih dipakai transaksi.');
        }

        return redirect()->route('inventory.index')->with('status', 'Persediaan dihapus.');
    }

    /** Mutasi stok manual: masuk (+) / keluar (−), validasi stok cukup. */
    public function mutasi(Request $request, Inventory $inventory): RedirectResponse
    {
        $data = $request->validate([
            'arah' => ['required', Rule::in(['masuk', 'keluar'])],
            'jumlah' => ['required', 'numeric', 'gt:0'],
            'keterangan' => ['nullable', 'string', 'max:255'],
        ]);

        if ($data['arah'] === 'masuk') {
            $inventory->update(['stok_masuk' => Money::add($inventory->stok_masuk, $data['jumlah'], 4)]);
        } else {
            $current = Money::sub($inventory->stok_masuk, $inventory->stok_keluar, 4);
            if (Money::gt($data['jumlah'], $current, 4)) {
                return back()->with('error', "Stok keluar melebihi stok saat ini ({$current}).");
            }
            $inventory->update(['stok_keluar' => Money::add($inventory->stok_keluar, $data['jumlah'], 4)]);
        }

        return redirect()->route('inventory.index')->with('status', "Stok {$inventory->kode_persediaan} diperbarui ({$data['arah']} {$data['jumlah']}).");
    }

    private function validasi(Request $request, bool $isCreate): array
    {
        return $request->validate([
            'nama_persediaan' => ['required', 'string', 'max:255'],
            'satuan' => ['required', 'string', 'max:50'],
            'harga_perolehan' => ['required', 'numeric', 'min:0'],
            'stok_masuk' => [$isCreate ? 'nullable' : 'prohibited', 'numeric', 'min:0'],
            'stok_keluar' => [$isCreate ? 'nullable' : 'prohibited', 'numeric', 'min:0'],
            'kode_coa' => ['nullable', 'string', 'exists:coa_detail,kode_coa'],
            'status' => ['required', Rule::in(['aktif', 'nonaktif'])],
        ]);
    }

    private function nextKode(): string
    {
        $last = Inventory::where('kode_persediaan', 'like', 'BRG%')->orderByDesc('kode_persediaan')->value('kode_persediaan');
        $n = 1;
        if ($last && is_numeric($tail = substr($last, 3))) {
            $n = (int) $tail + 1;
        }

        return 'BRG'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    private function coaOptions(): array
    {
        return ['' => '— tanpa akun —'] + CoaDetail::where('status', 'aktif')->orderBy('kode_coa')->get()
            ->mapWithKeys(fn ($c) => [$c->kode_coa => "{$c->kode_coa} — {$c->nama_coa}"])->all();
    }
}
