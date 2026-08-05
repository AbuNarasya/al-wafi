<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Http\Requests\JalurPendaftaranRequest;
use App\Models\JalurPendaftaran;
use App\Http\Controllers\Concerns\MengaturUrutanTampil;
use App\Services\Modules\JalurPendaftaranService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Master Jalur Pendaftaran (reguler/tahfizh/beasiswa, dst.). Controller tipis →
 * JalurPendaftaranService. Nilai `kode` dipakai santri.jalur.
 */
class JalurPendaftaranController extends Controller
{
    use MengaturUrutanTampil;

    public function __construct(private readonly JalurPendaftaranService $service) {}

    protected function kelasUrutan(): string
    {
        return JalurPendaftaran::class;
    }

    public function index(): View
    {
        return view('jalur-pendaftaran.index', ['rows' => $this->service->list()]);
    }

    public function create(): View
    {
        return view('jalur-pendaftaran.form', ['row' => new JalurPendaftaran(['status' => 'aktif']), 'baru' => true]);
    }

    public function store(JalurPendaftaranRequest $request): RedirectResponse
    {
        try {
            $this->service->create($request->validated());
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('jalur_pendaftaran.index')->with('status', 'Jalur pendaftaran berhasil ditambahkan.');
    }

    public function edit(string $kode): View
    {
        $row = JalurPendaftaran::findOrFail($kode);

        return view('jalur-pendaftaran.form', ['row' => $row, 'baru' => false]);
    }

    public function update(JalurPendaftaranRequest $request, string $kode): RedirectResponse
    {
        try {
            $this->service->update($kode, $request->validated());
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('jalur_pendaftaran.index')->with('status', 'Jalur pendaftaran berhasil diperbarui.');
    }

    public function destroy(string $kode): RedirectResponse
    {
        try {
            $this->service->remove($kode);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('jalur_pendaftaran.index')->with('status', 'Jalur pendaftaran berhasil dihapus.');
    }
}
