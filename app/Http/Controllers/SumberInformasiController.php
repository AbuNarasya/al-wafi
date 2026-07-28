<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\SumberInformasi;
use App\Services\Modules\SumberInformasiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Master Sumber Informasi PPSB. Controller tipis → SumberInformasiService. */
class SumberInformasiController extends Controller
{
    public function __construct(private readonly SumberInformasiService $service) {}

    public function index(): View
    {
        return view('sumber-informasi.index', ['rows' => $this->service->list()]);
    }

    public function create(): View
    {
        return view('sumber-informasi.form', ['row' => new SumberInformasi(['status' => 'aktif']), 'baru' => true]);
    }

    public function store(Request $request): RedirectResponse
    {
        try {
            $this->service->create($this->validated($request, true));
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('sumber_informasi.index')->with('status', 'Sumber informasi ditambahkan.');
    }

    public function edit(string $kode): View
    {
        return view('sumber-informasi.form', ['row' => SumberInformasi::findOrFail($kode), 'baru' => false]);
    }

    public function update(Request $request, string $kode): RedirectResponse
    {
        try {
            $this->service->update($kode, $this->validated($request, false) + ['kode' => $kode]);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('sumber_informasi.index')->with('status', 'Sumber informasi diperbarui.');
    }

    public function destroy(string $kode): RedirectResponse
    {
        try {
            $this->service->remove($kode);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('sumber_informasi.index')->with('status', 'Sumber informasi dihapus.');
    }

    private function validated(Request $request, bool $baru): array
    {
        $data = $request->validate([
            'kode' => $baru ? ['required', 'string', 'max:50', Rule::unique('sumber_informasi', 'kode')] : ['prohibited'],
            'nama' => ['required', 'string', 'max:255'],
            'urutan' => ['nullable', 'integer', 'min:0'],
            'butuh_keterangan' => ['nullable', 'boolean'],
            'status' => ['required', Rule::in(['aktif', 'nonaktif'])],
        ]);
        $data['butuh_keterangan'] = $request->boolean('butuh_keterangan');

        return $data;
    }
}
