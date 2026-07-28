<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Services\Modules\PotonganGelombangService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Master Potongan Uang Pangkal per Gelombang (early-bird). Create/list/remove
 * (tanpa edit — hapus lalu buat ulang). Controller tipis → service.
 */
class PotonganGelombangController extends Controller
{
    public function __construct(private readonly PotonganGelombangService $service) {}

    public function index(): View
    {
        return view('potongan-gelombang.index', ['rows' => $this->service->list()]);
    }

    public function create(): View
    {
        return view('potongan-gelombang.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tahun_ajaran' => ['required', 'string', 'exists:tahun_ajaran,kode'],
            'gelombang' => ['required', 'integer', 'min:1'],
            'kode_jenjang' => ['nullable', 'string', 'max:255'],
            'potongan' => ['required', 'numeric', 'min:0'],
            'masa_berlaku_hari' => ['required', 'integer', 'min:1'],
            'aktif' => ['nullable', 'boolean'],
            'keterangan' => ['nullable', 'string'],
        ]);
        $data['kode_jenjang'] = $data['kode_jenjang'] ?: null;
        $data['aktif'] = $request->boolean('aktif');

        try {
            $this->service->create($data);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('potongan_gelombang.index')->with('status', 'Potongan gelombang berhasil ditambahkan.');
    }

    public function destroy(int $id): RedirectResponse
    {
        try {
            $this->service->remove($id);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('potongan_gelombang.index')->with('status', 'Potongan gelombang berhasil dihapus.');
    }
}
