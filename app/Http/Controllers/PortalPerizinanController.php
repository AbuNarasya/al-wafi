<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\Perizinan;
use App\Models\Santri;
use App\Models\TipeIzin;
use App\Services\Modules\PerizinanService;
use App\Services\PortalWali\SesiWali;
use App\Support\Unggahan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Menu PERIZINAN di portal wali (Perizinan Insidental, 4 Nov 2026): ajukan, pantau, batalkan — per anak aktif. */
class PortalPerizinanController extends Controller
{
    public function __construct(private readonly PerizinanService $svc) {}

    private function anak()
    {
        return Santri::where('id_wali', SesiWali::wali()->id)->where('status', 'aktif')->orderBy('nama')->get(['id', 'nama', 'id_wali', 'status']);
    }

    private function milikSaya(int $id): Perizinan
    {
        return Perizinan::with(['santri:id,nama', 'tipe'])->where('id_wali', SesiWali::wali()->id)->findOrFail($id);
    }

    public function index(Request $request): View
    {
        $anak = $this->anak();
        $pilih = $anak->firstWhere('id', (int) $request->query('anak')) ?? $anak->first();

        return view('portal.perizinan', ['anak' => $anak, 'pilih' => $pilih,
            'daftar' => $pilih ? Perizinan::with('tipe')->where('id_santri', $pilih->id)->whereNull('id_jadwal')->orderByDesc('rencana_keluar')->limit(30)->get() : collect()]);
    }

    public function form(Request $request): View|RedirectResponse
    {
        $anak = $this->anak();
        if ($anak->isEmpty()) {
            return redirect()->route('portal.perizinan')->with('error', __('Perizinan hanya untuk santri aktif.'));
        }

        return view('portal.perizinan-ajukan', ['anak' => $anak, 'pilih' => (int) $request->query('anak') ?: $anak->first()->id,
            'tipe' => TipeIzin::where('aktif', true)->where('rutin', false)->orderBy('urutan')->get(),
            'keluarga' => SesiWali::wali(), 'wakilWajib' => PerizinanService::pengaturan()->wakil_wajib]);
    }

    public function ajukan(Request $request): RedirectResponse
    {
        // Wali wajib memilih siapa yang menjemput (Tahap 1, 4 Nov 2026).
        $d = $request->validate(['penjemput_jenis' => ['required', 'in:wali,wakil'], 'id_santri' => ['required', 'integer']] + PerizinanController::aturan(), [
            'rencana_keluar.required' => __('Isi waktu keluar.'), 'rencana_kembali.required' => __('Isi waktu kembali.'),
            'penjemput_hp.regex' => __('Nomor HP penjemput tidak sah.'), 'penjemput_jenis.required' => __('Pilih siapa yang menjemput.'),
        ]);
        $santri = $this->anak()->firstWhere('id', (int) $d['id_santri']) ?? abort(404);
        try {
            $z = $this->svc->ajukan(Santri::findOrFail($santri->id), $d, SesiWali::wali(), (string) SesiWali::peran($request), lampiran: $request->file('lampiran'), fotoIdentitas: $request->file('penjemput_foto'));
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('portal.perizinan.lihat', $z->id)->with('status', $z->disetujui_otomatis
            ? __('Izin disetujui. Tunjukkan kepada petugas pos saat menjemput.') : __('Pengajuan izin terkirim. Anda akan dikabari setelah diputuskan.'));
    }

    public function lihat(int $id): View
    {
        return view('portal.perizinan-lihat', ['z' => $this->milikSaya($id)]);
    }

    public function batal(int $id): RedirectResponse
    {
        try {
            $this->svc->batalkanOlehWali($this->milikSaya($id), SesiWali::wali());
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', __('Pengajuan izin dibatalkan.'));
    }

    public function lampiran(int $id)
    {
        $z = $this->milikSaya($id);
        abort_unless($z->lampiran_path, 404);

        return Unggahan::sajikan('local', $z->lampiran_path, $z->lampiran_nama);
    }

    public function fotoPenjemput(int $id)
    {
        $z = $this->milikSaya($id);
        abort_unless($z->penjemput_foto_path, 404);

        return Unggahan::sajikan('local', $z->penjemput_foto_path, $z->penjemput_foto_nama);
    }
}
