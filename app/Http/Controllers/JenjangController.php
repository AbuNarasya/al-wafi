<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Http\Controllers\Concerns\MengaturUrutanTampil;
use App\Http\Requests\JenjangRequest;
use App\Models\Jenjang;
use App\Services\Modules\JenjangService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Master Jenjang (Setting Awal). Sumber tunggal daftar jenjang untuk santri,
 * jenis biaya, tarif SPP, potongan gelombang, dan target santri.
 */
class JenjangController extends Controller
{
    use MengaturUrutanTampil;

    public function __construct(private readonly JenjangService $service) {}

    protected function kelasUrutan(): string
    {
        return Jenjang::class;
    }

    public function index(): View
    {
        return view('jenjang.index', ['rows' => $this->service->list()]);
    }

    public function create(): View
    {
        return view('jenjang.form', ['row' => new Jenjang(['status' => 'aktif']), 'baru' => true]);
    }

    public function store(JenjangRequest $request): RedirectResponse
    {
        try {
            $this->service->create($request->tersimpan());
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('jenjang.index')->with('status', 'Jenjang berhasil ditambahkan.');
    }

    public function edit(string $kode): View
    {
        return view('jenjang.form', ['row' => $this->service->get($kode), 'baru' => false]);
    }

    public function update(JenjangRequest $request, string $kode): RedirectResponse
    {
        try {
            $this->service->update($kode, $request->tersimpan());
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('jenjang.index')->with('status', 'Jenjang berhasil diperbarui.');
    }

    public function destroy(string $kode): RedirectResponse
    {
        try {
            $this->service->remove($kode);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('jenjang.index')->with('status', 'Jenjang dihapus.');
    }
}
