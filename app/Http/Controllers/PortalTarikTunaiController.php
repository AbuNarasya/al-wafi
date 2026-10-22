<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Services\Modules\PenarikanTunaiService;
use App\Services\PortalWali\SesiWali;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Portal Wali — pengajuan tarik tunai kegiatan (uang saku dibawa ke outbound/rihlah).
 * Di bawah /wali/tarik-tunai, BUKAN /wali/data: transaksi uang tak boleh terbuka bagi
 * pemegang link verifikasi.
 */
class PortalTarikTunaiController extends Controller
{
    public function __construct(private readonly PenarikanTunaiService $service) {}

    public function index(): View
    {
        return view('portal.tarik-tunai', ['sesi' => $this->service->sesiUntukWali(SesiWali::wali())]);
    }

    public function ajukan(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'id_sesi' => ['required', 'integer'], 'id_santri' => ['required', 'integer'],
            'nominal' => ['required', 'numeric', 'gt:0'], 'pin' => ['nullable', 'string'],
        ]);

        return $this->jalankan($request, fn () => $this->service->ajukan(SesiWali::wali(), (string) SesiWali::peran($request),
            (int) $data['id_sesi'], (int) $data['id_santri'], (string) $data['nominal'], $data['pin'] ?? null),
            fn ($p) => __('Pengajuan :nomor terkirim. Uang saku sebesar itu kini dicadangkan sampai diputus bagian kesantrian.', ['nomor' => $p->nomor]));
    }

    public function ubah(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['nominal' => ['required', 'numeric', 'gt:0'], 'pin' => ['nullable', 'string']]);

        return $this->jalankan($request, fn () => $this->service->ubahPengajuan(SesiWali::wali(), (string) SesiWali::peran($request),
            $id, (string) $data['nominal'], $data['pin'] ?? null),
            fn ($p) => __('Pengajuan :nomor diubah — menunggu persetujuan ulang.', ['nomor' => $p->nomor]));
    }

    public function batal(Request $request, int $id): RedirectResponse
    {
        return $this->jalankan($request, fn () => $this->service->batalPengajuan(SesiWali::wali(), $id),
            fn ($p) => __('Pengajuan :nomor dibatalkan — cadangan uang saku dilepas.', ['nomor' => $p->nomor]));
    }

    private function jalankan(Request $request, \Closure $aksi, \Closure $pesan): RedirectResponse
    {
        try {
            $p = $aksi();
        } catch (AppException $e) {
            return PortalDompetController::tanganiGalat($e, $request);
        }

        return redirect()->route('portal.tarik_tunai.index')->with('status', $pesan($p));
    }
}
