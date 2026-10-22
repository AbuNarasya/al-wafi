<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\DokumenPengajuanKeluar;
use App\Models\PengajuanKeluar;
use App\Models\Santri;
use App\Services\Modules\PengunduranDiriService;
use App\Services\PortalWali\PinTransaksi;
use App\Services\PortalWali\SesiWali;
use App\Support\Unggahan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Portal wali — PENGUNDURAN DIRI santri aktif (21 Okt 2026): ajukan (+ surat, PIN wali), pantau tahapnya,
 * perbaiki bila dikembalikan, batalkan sebelum diputus kepala sekolah, unduh surat mutasi/SK.
 * Di bawah /wali/anak/… — BUKAN /wali/data (sesi mode verifikasi hanya boleh ke sana).
 */
class PortalPengunduranDiriController extends Controller
{
    public function __construct(private readonly PengunduranDiriService $svc) {}

    public function form(int $id): View
    {
        $s = $this->anak($id);
        $p = PengajuanKeluar::with('dokumen')->where('id_santri', $s->id)->latest('id')->first();

        return view('portal.pengunduran-diri', ['santri' => $s, 'p' => $p, 'maksByte' => Unggahan::MAKS_KB * 1024,
            'bolehAjukan' => $s->status === 'aktif' && (! $p || ! in_array($p->status, PengajuanKeluar::BERJALAN, true))]);
    }

    public function ajukan(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'max:2000'], 'rencana_tanggal' => ['nullable', 'date'],
            'surat' => Unggahan::aturan(), 'pin' => ['nullable', 'string']], ['surat.required' => __('Unggah surat pengajuan pengunduran diri.')]);

        return $this->denganPin($request, $id, function (Santri $s) use ($data, $request) {
            $this->svc->ajukan($s, SesiWali::wali(), (string) SesiWali::peran($request), $data['alasan'], $data['rencana_tanggal'] ?? null, $request->file('surat'));

            return __('Pengajuan pengunduran diri terkirim dan menunggu verifikasi wali kelas.');
        });
    }

    public function ajukanUlang(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'max:2000'], 'rencana_tanggal' => ['nullable', 'date'],
            'surat' => Unggahan::aturan(wajib: false), 'pin' => ['nullable', 'string']]);

        return $this->denganPin($request, $id, function (Santri $s) use ($data, $request) {
            $p = PengajuanKeluar::where('id_santri', $s->id)->where('status', 'dikembalikan')->latest('id')->firstOrFail();
            $this->svc->ajukanUlang($p, $data['alasan'], $data['rencana_tanggal'] ?? null, $request->file('surat'), SesiWali::wali()->id);

            return __('Pengajuan dikirim ulang ke wali kelas.');
        });
    }

    public function batal(Request $request, int $id): RedirectResponse
    {
        $request->validate(['pin' => ['nullable', 'string']]);

        return $this->denganPin($request, $id, function (Santri $s) {
            $p = PengajuanKeluar::where('id_santri', $s->id)->whereIn('status', PengajuanKeluar::BERJALAN)->latest('id')->firstOrFail();
            $this->svc->batalkanOlehWali($p);

            return __('Pengajuan pengunduran diri dibatalkan.');
        });
    }

    public function dokumen(int $dokumen): StreamedResponse
    {
        $d = DokumenPengajuanKeluar::findOrFail($dokumen);
        $p = PengajuanKeluar::whereKey($d->id_pengajuan)->where('id_wali', SesiWali::wali()->id)->firstOrFail();
        abort_unless((bool) $p, 404);

        return \App\Support\Unggahan::sajikan('local', $d->path, $d->nama_asli);
    }

    private function anak(int $id): Santri
    {
        return Santri::with('kelas:id,nama')->where('id_wali', SesiWali::wali()->id)->findOrFail($id);
    }

    private function denganPin(Request $request, int $id, \Closure $aksi): RedirectResponse
    {
        $s = $this->anak($id);
        try {
            (new PinTransaksi(SesiWali::wali(), (string) SesiWali::peran($request)))->periksa($request->input('pin'));
            $pesan = $aksi($s);
        } catch (AppException $e) {
            if ($e->status === 428) {
                return PortalDompetController::tanganiGalat($e, $request);
            }

            return back()->withInput($request->except(['pin', 'surat']))->with('error', $e->getMessage());
        }

        return redirect()->route('portal.mundur', $s->id)->with('status', $pesan);
    }
}
