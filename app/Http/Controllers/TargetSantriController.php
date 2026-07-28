<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\TargetSantri;
use App\Services\Modules\TargetSantriService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Master Target Santri per Tahun Ajaran & Jenjang. Controller tipis → service.
 */
class TargetSantriController extends Controller
{
    public function __construct(private readonly TargetSantriService $service) {}

    public function index(): View
    {
        return view('target-santri.index', ['rows' => $this->service->list()]);
    }

    public function create(): View
    {
        return view('target-santri.form', ['row' => new TargetSantri(), 'baru' => true]);
    }

    public function store(Request $request): RedirectResponse
    {
        try {
            $this->service->create($this->validated($request));
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('target_santri.index')->with('status', 'Target santri berhasil ditambahkan.');
    }

    public function edit(int $id): View
    {
        return view('target-santri.form', ['row' => TargetSantri::findOrFail($id), 'baru' => false]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        try {
            $this->service->update($id, $this->validated($request));
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('target_santri.index')->with('status', 'Target santri berhasil diperbarui.');
    }

    public function destroy(int $id): RedirectResponse
    {
        try {
            $this->service->remove($id);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('target_santri.index')->with('status', 'Target santri berhasil dihapus.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'tahun_ajaran' => ['required', 'string', 'exists:tahun_ajaran,kode'],
            'kode_jenjang' => ['required', 'string', 'exists:jenjang,kode'],
            // Total diisi otomatis dari L+P di form, tapi tetap divalidasi agar
            // pengiriman langsung (tanpa JS) tidak menghasilkan total kosong.
            'target' => ['required', 'integer', 'min:0'],
            'target_l' => ['nullable', 'integer', 'min:0'],
            'target_p' => ['nullable', 'integer', 'min:0'],
            'keterangan' => ['nullable', 'string'],
        ]);
    }
}
