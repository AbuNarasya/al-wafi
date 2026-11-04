<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\TermTemplate;
use App\Services\Modules\SuratPernyataanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Surat Pernyataan Wali (PPSB → Setting Awal). Controller tipis → SuratPernyataanService. */
class SuratPernyataanController extends Controller
{
    public function __construct(private readonly SuratPernyataanService $service) {}

    public function index(): View
    {
        return view('surat-pernyataan.index', ['rows' => $this->service->list()]);
    }

    public function create(): View
    {
        // Versi baru berangkat dari teks yang sedang aktif — yang biasanya terjadi
        // adalah merevisi beberapa butir, bukan menulis ulang dari nol.
        $aktif = SuratPernyataanService::aktif();

        return view('surat-pernyataan.form', ['row' => new TermTemplate([
            'judul' => $aktif?->judul ?? 'Surat Pernyataan Wali Santri',
            'isi' => $aktif?->isi,
            'berlaku_mulai' => now()->toDateString(),
        ]), 'baru' => true]);
    }

    public function store(Request $request): RedirectResponse
    {
        try {
            $surat = $this->service->create($this->validated($request), (int) $request->user()->id_pengguna, $request->file('berkas_pdf'));
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('surat_pernyataan.index')->with('status', "Surat pernyataan versi {$surat->versi} disimpan"
            .($surat->status === 'aktif' ? ' dan aktif.' : '. Tekan "Jadikan Aktif" bila sudah siap dipakai.'));
    }

    public function edit(int $id): View|RedirectResponse
    {
        $row = TermTemplate::withCount('persetujuan')->findOrFail($id);
        if (SuratPernyataanService::dipakai($row) > 0) {
            return redirect()->route('surat_pernyataan.index')
                ->with('error', "Versi {$row->versi} sudah disetujui wali dan terkunci. Buat versi baru untuk mengubah isinya.");
        }

        return view('surat-pernyataan.form', ['row' => $row, 'baru' => false]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        try {
            $surat = $this->service->update($id, $this->validated($request), $request->file('berkas_pdf'), $request->boolean('hapus_pdf'));
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('surat_pernyataan.index')->with('status', "Surat pernyataan versi {$surat->versi} diperbarui.");
    }

    public function aktifkan(int $id): RedirectResponse
    {
        try {
            $surat = $this->service->aktifkan($id);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('surat_pernyataan.index')
            ->with('status', "Versi {$surat->versi} kini aktif — inilah yang tampil kepada wali saat mendaftar.");
    }

    public function destroy(int $id): RedirectResponse
    {
        try {
            $this->service->remove($id);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('surat_pernyataan.index')->with('status', 'Surat pernyataan dihapus.');
    }

    /** PDF surat versi ini — untuk petugas (unduhan wali lewat PortalSuratPernyataanController). */
    public function pdf(int $id): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $surat = TermTemplate::findOrFail($id);
        abort_unless($surat->berkas_pdf, 404);

        return \App\Support\Unggahan::sajikan('local', $surat->berkas_pdf, $surat->berkas_pdf_nama);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'judul' => ['required', 'string', 'max:255'],
            'isi' => ['required', 'string', 'max:50000'],
            'berlaku_mulai' => ['required', 'date'],
            // Opsional, PDF saja, ≤ 2 MB (lebih longgar dari batas unggahan umum).
            'berkas_pdf' => ['nullable', 'file', 'mimes:pdf', 'max:'.SuratPernyataanService::MAKS_PDF_KB],
        ], [
            'berkas_pdf.mimes' => 'Berkas surat pernyataan harus PDF.',
            'berkas_pdf.max' => 'Berkas PDF maksimal 2 MB.',
        ]);
    }
}
