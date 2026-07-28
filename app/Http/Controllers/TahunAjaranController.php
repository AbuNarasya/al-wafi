<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Http\Requests\TahunAjaranRequest;
use App\Models\TahunAjaran;
use App\Services\Modules\TahunAjaranService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Master Tahun Ajaran (PPSB → Master). Controller tipis → TahunAjaranService.
 * kode TA dirujuk jenis biaya / jalur / potongan / target / santri.
 */
class TahunAjaranController extends Controller
{
    public function __construct(private readonly TahunAjaranService $service) {}

    public function index(): View
    {
        return view('tahun-ajaran.index', ['rows' => $this->service->list()]);
    }

    public function create(): View
    {
        return view('tahun-ajaran.form', ['row' => new TahunAjaran(['status' => 'aktif']), 'baru' => true]);
    }

    public function store(TahunAjaranRequest $request): RedirectResponse
    {
        try {
            $this->service->create($request->tersimpan());
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('tahun_ajaran.index')->with('status', 'Tahun ajaran berhasil ditambahkan.');
    }

    public function edit(int $id): View
    {
        return view('tahun-ajaran.form', ['row' => $this->service->get($id), 'baru' => false]);
    }

    public function update(TahunAjaranRequest $request, int $id): RedirectResponse
    {
        try {
            $this->service->update($id, $request->tersimpan());
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('tahun_ajaran.index')->with('status', 'Tahun ajaran berhasil diperbarui.');
    }

    public function destroy(int $id): RedirectResponse
    {
        try {
            $this->service->remove($id);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('tahun_ajaran.index')->with('status', 'Tahun ajaran dihapus.');
    }
}
