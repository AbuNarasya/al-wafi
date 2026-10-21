<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\KartuSantri;
use App\Models\PinSantri;
use App\Models\Santri;
use App\Services\Modules\KartuSantriService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Layar staf KARTU SANTRI — pasang kartu RFID (tempel kartu di pembaca USB), blokir,
 * buka blokir. Hak: kartu-santri lihat / buat (pasang) / ubah (blokir & buka).
 * Logika: [[KartuSantriService]].
 */
class KartuSantriController extends Controller
{
    public function __construct(private readonly KartuSantriService $kartu) {}

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $tab = $request->query('tab') === 'diblokir' ? 'diblokir' : 'santri';

        $santri = Santri::with(['kartuAktif', 'dompet', 'jenjang:kode,nama'])->where('status', 'aktif')
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('nama', 'ilike', "%{$q}%")->orWhere('nis', 'ilike', "%{$q}%")))
            ->when($request->boolean('tanpa_kartu'), fn ($w) => $w->whereDoesntHave('kartuAktif'))
            ->orderBy('nama')->paginate(30)->withQueryString();

        return view('kartu-santri.index', [
            'tab' => $tab,
            'q' => $q,
            'santri' => $santri,
            'punyaPin' => PinSantri::whereIn('id_santri', $santri->pluck('id'))->pluck('id_santri')->flip(),
            'diblokir' => $tab === 'diblokir'
                ? KartuSantri::with('santri:id,nama,nis')->where('status', 'diblokir')->orderByDesc('diblokir_pada')->paginate(30)->withQueryString()
                : null,
            'batasBawaan' => KartuSantriService::batasBawaan(),
            'ringkas' => [
                'aktif' => Santri::where('status', 'aktif')->count(),
                'berkartu' => KartuSantri::where('status', 'aktif')->whereHas('santri', fn ($s) => $s->where('status', 'aktif'))->count(),
            ],
        ]);
    }

    public function pasang(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'id_santri' => ['required', 'integer', 'exists:santri,id'],
            'uid' => ['required', 'string', 'max:80'],
        ]);

        try {
            $k = $this->kartu->pasang((int) $data['id_santri'], $data['uid'], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Kartu terpasang untuk '.$k->santri->nama.'.');
    }

    public function blokir(Request $request, KartuSantri $kartu): RedirectResponse
    {
        $data = $request->validate(['alasan' => ['nullable', 'string', 'max:255']]);
        $this->kartu->blokir($kartu, $data['alasan'] ?? 'Kartu hilang', $request->user()->id_pengguna);

        return back()->with('status', 'Kartu '.$kartu->santri->nama.' diblokir.');
    }

    /** Hapus pendaftaran: tanpa riwayat → dihapus; punya riwayat → dilepas. UID-nya bebas dipasang ke santri lain. */
    public function hapus(Request $request, KartuSantri $kartu): RedirectResponse
    {
        $nama = $kartu->santri?->nama;
        $hasil = $this->kartu->hapusPendaftaran($kartu, $request->user()->id_pengguna);

        return back()->with('status', $hasil === 'dihapus'
            ? "Pendaftaran kartu {$nama} dihapus. Kartunya bisa dipasang ke santri lain."
            : "Kartu {$nama} dilepas (riwayat transaksinya tetap tercatat atas nama {$nama}). Kartunya bisa dipasang ke santri lain.");
    }

    public function buka(KartuSantri $kartu): RedirectResponse
    {
        try {
            $this->kartu->bukaBlokir($kartu);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Blokir kartu '.$kartu->santri->nama.' dibuka.');
    }
}
