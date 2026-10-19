<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\PengajuanTarikTunai;
use App\Models\SesiTarikTunai;
use App\Services\Modules\PenarikanTunaiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Persetujuan pengajuan tarik tunai kegiatan — layar KESANTRIAN. Hak `penarikan-tunai`:
 * lihat / ubah (setujui, boleh nominal lebih kecil, atau tolak).
 */
class PersetujuanTarikTunaiController extends Controller
{
    public function __construct(private readonly PenarikanTunaiService $service) {}

    public function index(Request $request): View
    {
        // Sesi yang masih bisa diputus: berjalan & batas persetujuannya belum lewat.
        $sesi = SesiTarikTunai::where('status', 'berjalan')->where('batas_persetujuan', '>=', now())
            ->orderBy('batas_persetujuan')->get();
        $menunggu = PengajuanTarikTunai::with(['santri:id,nama,nis,kode_jenjang,tingkat', 'wali', 'sesi'])
            ->whereIn('id_sesi', $sesi->pluck('id_sesi'))->where('status', 'diajukan')->orderBy('id')->get()->groupBy('id_sesi');
        $diputus = PengajuanTarikTunai::with(['santri:id,nama,nis', 'sesi', 'pemutus'])
            ->whereIn('status', ['disetujui', 'ditolak'])->orderByDesc('diputus_pada')->limit(30)->get();

        return view('tarik-tunai.persetujuan', compact('sesi', 'menunggu', 'diputus'));
    }

    public function setujui(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['nominal' => ['nullable', 'numeric', 'gt:0']]);
        try {
            $p = $this->service->setujui($id, isset($data['nominal']) ? (string) $data['nominal'] : null, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "{$p->nomor} disetujui Rp ".number_format((float) $p->nominal_disetujui, 0, ',', '.')." untuk {$p->santri->nama}.");
    }

    public function tolak(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'max:255']]);
        try {
            $p = $this->service->tolak($id, $data['alasan'], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "{$p->nomor} ditolak — wali dikabari.");
    }
}
