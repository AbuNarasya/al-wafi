<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Http\Requests\WaliRequest;
use App\Services\Modules\WaliService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Master Wali (satu wali = satu keluarga). Controller tipis → WaliService.
 * nama & telepon otomatis disalin dari kontak utama; telepon unik.
 */
class WaliController extends Controller
{
    public function __construct(private readonly WaliService $service) {}

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        return view('wali.index', [
            'rows' => $this->service->list($q !== '' ? $q : null),
            'q' => $q,
        ]);
    }

    public function create(): View
    {
        return view('wali.form', ['wali' => new \App\Models\Wali(['kontak_utama' => 'ayah', 'status' => 'aktif']), 'baru' => true]);
    }

    public function store(WaliRequest $request): RedirectResponse
    {
        try {
            $wali = $this->service->create($request->validated());
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('wali.index')->with('status', "Wali {$wali->nama} berhasil ditambahkan.");
    }

    public function edit(int $id): View
    {
        return view('wali.form', ['wali' => $this->service->get($id), 'baru' => false]);
    }

    public function update(WaliRequest $request, int $id): RedirectResponse
    {
        try {
            $wali = $this->service->update($id, $request->validated());
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('wali.index')->with('status', "Wali {$wali->nama} berhasil diperbarui.");
    }

    public function destroy(int $id): RedirectResponse
    {
        try {
            $this->service->remove($id);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('wali.index')->with('status', 'Wali berhasil dihapus.');
    }
}
