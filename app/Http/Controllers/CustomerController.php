<?php

namespace App\Http\Controllers;

use App\Http\Requests\CustomerRequest;
use App\Models\CoaDetail;
use App\Models\Customer;
use App\Models\CustomerType;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $customers = Customer::query()->with('jenis')
            ->when($q !== '', fn ($query) => $query->where(
                fn ($w) => $w->where('kode_customer', 'ilike', "%{$q}%")->orWhere('nama_customer', 'ilike', "%{$q}%"),
            ))
            ->orderBy('kode_customer')->get();

        return view('customers.index', compact('customers', 'q'));
    }

    public function create(): View
    {
        return view('customers.form', ['customer' => new Customer(['status' => 'aktif']), ...$this->opsi()]);
    }

    public function store(CustomerRequest $request): RedirectResponse
    {
        Customer::create($request->tersimpan());

        return redirect()->route('customers.index')->with('status', 'Customer berhasil ditambahkan.');
    }

    public function edit(Customer $customer): View
    {
        return view('customers.form', ['customer' => $customer, ...$this->opsi()]);
    }

    public function update(CustomerRequest $request, Customer $customer): RedirectResponse
    {
        $customer->update($request->tersimpan());

        return redirect()->route('customers.index')->with('status', 'Customer berhasil diperbarui.');
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        try {
            $customer->delete();
        } catch (QueryException $e) {
            return redirect()->route('customers.index')->with('error', 'Customer tidak bisa dihapus karena masih dipakai transaksi.');
        }

        return redirect()->route('customers.index')->with('status', 'Customer berhasil dihapus.');
    }

    private function opsi(): array
    {
        $coa = ['' => '— Pakai default —'] + CoaDetail::where('status', 'aktif')->orderBy('kode_coa')->get()
            ->mapWithKeys(fn ($c) => [$c->kode_coa => "{$c->kode_coa} — {$c->nama_coa}"])->all();

        return [
            'jenisOptions' => CustomerType::where('status', 'aktif')->orderBy('kode_jenis_customer')->get()
                ->mapWithKeys(fn ($t) => [$t->kode_jenis_customer => "{$t->kode_jenis_customer} — {$t->nama}"])->all(),
            'coaOptions' => $coa,
        ];
    }
}
