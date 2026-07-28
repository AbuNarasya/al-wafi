<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\TipeBiaya;
use App\Services\Modules\TipeBiayaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Master Tipe Biaya (Setting Awal). Controller tipis → TipeBiayaService. */
class TipeBiayaController extends Controller
{
    public function __construct(private readonly TipeBiayaService $service) {}

    public function index(): View
    {
        return view('tipe-biaya.index', ['rows' => $this->service->list()]);
    }

    public function create(): View
    {
        return view('tipe-biaya.form', ['row' => new TipeBiaya(['status' => 'aktif', 'perilaku' => 'lain']), 'baru' => true]);
    }

    public function store(Request $request): RedirectResponse
    {
        try {
            $this->service->create($this->validated($request, true));
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('tipe_biaya.index')->with('status', 'Tipe biaya ditambahkan.');
    }

    public function edit(string $kode): View
    {
        return view('tipe-biaya.form', ['row' => TipeBiaya::findOrFail($kode), 'baru' => false]);
    }

    public function update(Request $request, string $kode): RedirectResponse
    {
        try {
            $this->service->update($kode, $this->validated($request, false) + ['kode' => $kode]);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('tipe_biaya.index')->with('status', 'Tipe biaya diperbarui.');
    }

    public function destroy(string $kode): RedirectResponse
    {
        try {
            $this->service->remove($kode);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('tipe_biaya.index')->with('status', 'Tipe biaya dihapus.');
    }

    private function validated(Request $request, bool $baru): array
    {
        return $request->validate([
            'kode' => $baru ? ['required', 'string', 'max:50', Rule::unique('tipe_biaya', 'kode')] : ['prohibited'],
            'nama' => ['required', 'string', 'max:255'],
            'perilaku' => ['required', Rule::in(array_keys(TipeBiaya::PERILAKU))],
            'urutan' => ['nullable', 'integer', 'min:0'],
            'keterangan' => ['nullable', 'string'],
            'status' => ['required', Rule::in(['aktif', 'nonaktif'])],
        ]);
    }
}
