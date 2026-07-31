<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\PotonganGelombang;
use App\Services\Modules\PotonganGelombangService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Master Potongan Uang Pangkal per Gelombang (early-bird). Controller tipis →
 * service. Suntingan TIDAK menyentuh tagihan yang sudah terbit: potongannya
 * disalin ke `potongan_uang_pangkal` saat tagihan lahir.
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
        return view('potongan-gelombang.form', [
            'row' => new PotonganGelombang(['gelombang' => 1, 'masa_berlaku_hari' => 7, 'aktif' => true]),
            'baru' => true,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validasi($request);

        try {
            $row = $this->service->create($data);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return $this->kembali($row, 'Potongan gelombang berhasil ditambahkan.');
    }

    public function edit(int $id): View
    {
        try {
            $row = $this->service->get($id);
        } catch (AppException $e) {
            abort($e->status, $e->getMessage());
        }

        return view('potongan-gelombang.form', ['row' => $row, 'baru' => false]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $data = $this->validasi($request);

        try {
            $row = $this->service->update($id, $data);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return $this->kembali($row, 'Potongan gelombang berhasil diperbarui.');
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

    /** @return array<string,mixed> */
    private function validasi(Request $request): array
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

        return $data;
    }

    /**
     * Kembali ke daftar, membawa PERINGATAN bila potongannya ≥ uang pangkal.
     * Sengaja bukan penolakan — lihat PotonganGelombangService::peringatanNominal.
     */
    private function kembali(PotonganGelombang $row, string $pesan): RedirectResponse
    {
        $redirect = redirect()->route('potongan_gelombang.index')->with('status', $pesan);
        $peringatan = $this->service->peringatanNominal($row);

        return $peringatan ? $redirect->with('error', $peringatan) : $redirect;
    }
}
