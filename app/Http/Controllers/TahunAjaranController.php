<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Http\Requests\TahunAjaranRequest;
use App\Models\TahunAjaran;
use App\Services\Modules\TahunAjaranService;
use App\Support\Akses;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        return view('tahun-ajaran.index', [
            'rows' => $this->service->list(),
            // Dipakai layar untuk menyebut angkanya di dialog konfirmasi — sel
            // tarif adalah satu-satunya rujukan yang boleh ikut terhapus.
            'selTarif' => $this->service->jumlahSelTarif(),
        ]);
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

    /**
     * Menyapu sel tarif menuntut hak UBAH di modul Tarif, bukan sekadar hak
     * hapus di sini: tanpa syarat itu, orang yang sengaja tak diberi akses tarif
     * bisa menghabiskan tarif satu tahun ajaran lewat pintu belakang.
     * Yang tak memenuhinya tetap kena pesan penghalang seperti sebelumnya.
     */
    public function destroy(Request $request, int $id): RedirectResponse
    {
        $ikutTarif = $request->boolean('ikut_tarif') && Akses::boleh('tarif', 'ubah');

        try {
            $this->service->remove($id, $ikutTarif);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('tahun_ajaran.index')
            ->with('status', 'Tahun ajaran dihapus'.($ikutTarif ? ' beserta seluruh tarifnya.' : '.'));
    }
}
