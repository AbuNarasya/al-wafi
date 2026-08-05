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
        //
        // Kuncinya JENIS + id, bukan id saja. Kotak ini memuat sepuluh jenis
        // dokumen (Budget, KasKeluar, PindahBuku, …) dan penomorannya berjalan
        // sendiri-sendiri, sehingga Usulan Anggaran #5 dan Pengajuan Pembayaran
        // #5 sama-sama ada. Dengan kunci id saja, kartu usulan anggaran
        // menampilkan nomor, keterangan, bagian, dan tautan milik pengajuan
        // pembayaran — keputusan persetujuan diambil di atas keterangan dokumen
        // yang sama sekali lain.
        $pengajuanIds = $items->where('jenis_dokumen', PengajuanPembayaranService::SUMBER)
            ->pluck('id_dokumen')->map(fn ($v) => (int) $v)->all();
        $docs = PengajuanPembayaran::with('bagian')->whereIn('id', $pengajuanIds)->get()
            ->keyBy(fn ($d) => PengajuanPembayaranService::SUMBER.'|'.$d->id);

        return view('approvals.inbox', compact('items', 'docs'));
    }

    public function approve(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['catatan' => ['nullable', 'string']]);
        $asal = $this->asalDokumen($request, $id);

        try {
            $this->service->approve($id, $request->user()->id_pengguna, $data['catatan'] ?? null);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return ($asal ?? redirect()->route('approvals.inbox'))->with('status', 'Pengajuan disetujui.');
    }

    public function reject(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'max:255']]);
        $asal = $this->asalDokumen($request, $id);

        try {
            $this->service->reject($id, $request->user()->id_pengguna, $data['alasan']);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return ($asal ?? redirect()->route('approvals.inbox'))
            ->with('status', 'Pengajuan ditolak & dikembalikan ke pemohon.');
    }

    /**
     * Kembali ke halaman dokumen bila keputusannya diambil dari sana — approver
     * yang sedang membaca rinciannya tak perlu dilempar ke kotak masuk.
     *
     * Tujuannya DIBANGUN dari instance-nya sendiri, bukan dari URL yang dikirim
     * form: menerima alamat dari isian berarti membuka pengalihan ke mana saja.
     * Alamat dicatat SEBELUM keputusan diambil, karena sesudahnya instance bisa
     * saja sudah selesai.
     */
    private function asalDokumen(Request $request, int $idInstance): ?RedirectResponse
    {
        if ($request->input('kembali') !== 'dokumen') {
            return null;
        }

        $inst = \App\Models\ApprovalInstance::find($idInstance);
        if (! $inst || $inst->jenis_dokumen !== PengajuanPembayaranService::SUMBER) {
            return null;
        }

        return redirect()->route('pengajuan.show', (int) $inst->id_dokumen);
    }
}
