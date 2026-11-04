<?php

namespace App\Http\Controllers;

use App\Models\JadwalPerizinanRutin;
use App\Models\Perizinan;
use App\Models\Santri;
use App\Services\Modules\PerizinanRutinService;
use App\Services\Modules\PerizinanService;
use App\Services\Modules\TunggakanIzin;
use App\Services\PortalWali\SesiWali;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Menu PERIZINAN RUTIN di portal wali (Penjengukan & Kepulangan, Tahap 2): jadwal terdekat + ajukan per jadwal. */
class PortalPerizinanRutinController extends Controller
{
    public function __construct(private readonly PerizinanRutinService $svc) {}

    private function anak()
    {
        return Santri::where('id_wali', SesiWali::wali()->id)->where('status', 'aktif')->orderBy('nama')
            ->get(['id', 'nama', 'id_wali', 'status', 'kode_jenjang', 'jenis_kelamin']);
    }

    public function index(): View
    {
        $anak = $this->anak();
        $jadwal = PerizinanRutinService::untukKeluarga($anak);
        $izin = Perizinan::whereIn('id_jadwal', $jadwal->pluck('id'))->whereIn('id_santri', $anak->pluck('id'))
            ->where('status', '!=', 'dibatalkan')->orderByDesc('id')->get()->groupBy(fn ($z) => $z->id_jadwal.'|'.$z->id_santri);

        return view('portal.perizinan-rutin', ['anak' => $anak, 'jadwal' => $jadwal, 'izin' => $izin,
            'riwayat' => Perizinan::with('tipe')->whereNotNull('id_jadwal')->whereIn('id_santri', $anak->pluck('id'))
                ->where('rencana_kembali', '<', now())->orderByDesc('rencana_keluar')->limit(20)->get()]);
    }

    public function form(int $id): View|RedirectResponse
    {
        $j = $this->jadwalKeluarga($id);
        if (! $j->masihBisaDiajukan()) {
            return redirect()->route('portal.perizinan_rutin')->with('error', __('Pengajuan untuk jadwal ini sudah ditutup.'));
        }
        $calon = $this->calon($j);
        if ($calon->isEmpty()) {
            return redirect()->route('portal.perizinan_rutin')->with('error', __('Semua anak sudah diajukan untuk jadwal ini.'));
        }

        return view('portal.perizinan-rutin-ajukan', ['j' => $j, 'calon' => $calon, 'keluarga' => SesiWali::wali(),
            'wakilWajib' => PerizinanService::pengaturan()->wakil_wajib]);
    }

    public function ajukan(Request $request, int $id): RedirectResponse
    {
        $j = $this->jadwalKeluarga($id);
        $d = $request->validate(['anak' => ['required', 'array', 'min:1'], 'anak.*' => ['integer'],
            'penjemput_jenis' => ['required', 'in:wali,wakil']] + array_diff_key(PerizinanController::aturan(), array_flip(['kode_tipe', 'rencana_keluar', 'rencana_kembali'])), [
            'anak.required' => __('Pilih anak yang diajukan.'), 'penjemput_jenis.required' => __('Pilih siapa yang menjemput.'),
            'penjemput_hp.regex' => __('Nomor HP penjemput tidak sah.'),
        ]);
        $pilih = $this->calon($j)->whereIn('id', array_map('intval', $d['anak']));
        if ($pilih->isEmpty()) {
            return back()->withInput()->with('error', __('Pilih anak yang diajukan.'));
        }
        $hasil = $this->svc->ajukanBanyak($j, $pilih->map(fn ($s) => Santri::findOrFail($s->id)), $d, SesiWali::wali(),
            (string) SesiWali::peran($request), foto: $request->file('penjemput_foto'));
        if ($hasil['berhasil'] === []) {
            return back()->withInput()->with('error', implode(' ', $hasil['gagal']));
        }
        $pesan = __(':n pengajuan terkirim.', ['n' => count($hasil['berhasil'])]);
        foreach ($hasil['gagal'] as $nama => $galat) {
            $pesan .= ' '.$nama.': '.$galat;
        }

        return redirect()->route('portal.perizinan_rutin')->with($hasil['gagal'] === [] ? 'status' : 'error', $pesan);
    }

    private function jadwalKeluarga(int $id): JadwalPerizinanRutin
    {
        $j = JadwalPerizinanRutin::with(['tipe', 'cakupan'])->findOrFail($id);
        abort_unless($this->anak()->contains(fn ($s) => $j->mencakup($s)), 404);

        return $j;
    }

    /** Anak dalam cakupan yang belum punya pengajuan hidup untuk jadwal ini, + hambatan tunggakannya. */
    private function calon(JadwalPerizinanRutin $j)
    {
        $sudah = Perizinan::where('id_jadwal', $j->id)->whereNotIn('status', ['dibatalkan', 'ditolak'])->pluck('id_santri')->all();

        return $this->anak()->filter(fn ($s) => $j->mencakup($s) && ! in_array($s->id, $sudah, true))
            ->each(function ($s) use ($j) {
                $s->hambatan_tolak = TunggakanIzin::hambatan($j->tipe, $s, 'tolak');
                $s->hambatan_kembali = TunggakanIzin::hambatan($j->tipe, $s, 'tahan_kembali');
            })->values();
    }
}
