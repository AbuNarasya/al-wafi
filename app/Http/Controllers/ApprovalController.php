<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\PengajuanPembayaran;
use App\Services\Modules\ApprovalService;
use App\Services\Modules\PengajuanPembayaranService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Persetujuan Saya — kotak masuk approval. Wewenang menyetujui ditentukan
 * PERINGKAT & FUNGSI (bukan matriks modul), jadi rute ini di luar hak akses
 * modul (hanya wajib login). Controller tipis → ApprovalService.
 */
class ApprovalController extends Controller
{
    public function __construct(private readonly ApprovalService $service) {}

    public function inbox(Request $request): View
    {
        $items = $this->service->inbox($request->user()->id_pengguna);

        // Lampirkan info dokumen Pengajuan Pembayaran untuk ditampilkan.
        $pengajuanIds = $items->where('jenis_dokumen', PengajuanPembayaranService::SUMBER)
            ->pluck('id_dokumen')->map(fn ($v) => (int) $v)->all();
        $docs = PengajuanPembayaran::with('bagian')->whereIn('id', $pengajuanIds)->get()->keyBy('id');

        return view('approvals.inbox', compact('items', 'docs'));
    }

    public function approve(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['catatan' => ['nullable', 'string']]);

        try {
            $this->service->approve($id, $request->user()->id_pengguna, $data['catatan'] ?? null);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('approvals.inbox')->with('status', 'Pengajuan disetujui.');
    }

    public function reject(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'max:255']]);

        try {
            $this->service->reject($id, $request->user()->id_pengguna, $data['alasan']);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('approvals.inbox')->with('status', 'Pengajuan ditolak & dikembalikan ke pemohon.');
    }
}
