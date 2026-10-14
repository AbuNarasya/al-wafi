<?php

namespace App\Http\Controllers;

use App\Models\VerifikasiWali;
use App\Models\Wali;
use App\Services\PortalWali\LinkVerifikasi;
use App\Services\Ppsb\Tahap;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * STAF — Verifikasi Data Wali: membuat link verifikasi per keluarga, mengirimnya
 * lewat WhatsApp (wa.me, tanpa layanan berbayar), dan memantau progresnya.
 * Aturan link di LinkVerifikasi. Hak akses mengikuti modul Wali / Keluarga.
 */
class WaliVerifikasiController extends Controller
{
    public function __construct(private readonly LinkVerifikasi $link) {}

    public function index(Request $request): View
    {
        $perlu = LinkVerifikasi::keluargaPerlu();
        $wali = Wali::whereIn('id', $perlu)->where('status', 'aktif')
            ->with(['santri' => fn ($q) => $q->whereNotIn('status', Tahap::STATUS_FINAL)->orderBy('nama')->select('id', 'id_wali', 'nama', 'nis', 'kode_jenjang')])
            ->orderBy('nama')->get();
        $link = VerifikasiWali::whereNull('dicabut_pada')->whereIn('id_wali', $wali->pluck('id'))->get()->keyBy('id_wali');

        $baris = $wali->map(fn (Wali $w) => [
            'wali' => $w, 'link' => $link[$w->id] ?? null,
            'status' => isset($link[$w->id]) ? $link[$w->id]->status() : 'tanpa_link',
            'wa' => LinkVerifikasi::nomorWa($w) !== null,
        ]);
        $hitung = $baris->countBy('status');

        $status = (string) $request->query('status', '');
        $q = mb_strtolower(trim((string) $request->query('q', '')));
        $tersaring = $baris
            ->when($status !== '', fn ($c) => $c->where('status', $status))
            ->when($q !== '', fn ($c) => $c->filter(fn ($b) => str_contains(mb_strtolower($b['wali']->nama.' '.$b['wali']->santri->pluck('nama')->implode(' ').' '.$b['wali']->santri->pluck('nis')->implode(' ')), $q)))
            ->values();

        $halaman = LengthAwarePaginator::resolveCurrentPage();
        $per = 50;

        return view('wali.verifikasi', [
            'rows' => new LengthAwarePaginator($tersaring->forPage($halaman, $per)->values(), $tersaring->count(), $per, $halaman,
                ['path' => $request->url(), 'query' => $request->query()]),
            'total' => $baris->count(), 'hitung' => $hitung, 'status' => $status, 'q' => $q,
            'aktif' => LinkVerifikasi::aktif(),
        ]);
    }

    private const MATI = 'Fitur verifikasi data sedang dimatikan (VERIFIKASI_WALI_AKTIF). Nyalakan dulu di pengaturan server.';

    /**
     * Akhiri kampanye: cabut semua link yang belum selesai. Sengaja tetap bisa saat
     * saklar mati — justru saat itulah link yang tersebar perlu dipastikan mati.
     */
    public function akhiri(): RedirectResponse
    {
        $jumlah = $this->link->akhiriSemua();

        return back()->with('status', "{$jumlah} link yang belum selesai dicabut. Riwayat keluarga yang sudah selesai tetap tersimpan.");
    }

    public function buatMassal(Request $request): RedirectResponse
    {
        if (! LinkVerifikasi::aktif()) {
            return back()->with('error', self::MATI);
        }
        $data = $request->validate(['mulai' => ['required', 'date']], ['mulai.required' => 'Tanggal mulai wajib diisi.']);
        $jumlah = $this->link->buatMassal(Carbon::parse($data['mulai']), Auth::id());

        return back()->with('status', $jumlah > 0
            ? "{$jumlah} link verifikasi dibuat, berlaku mulai ".Carbon::parse($data['mulai'])->locale('id')->translatedFormat('j F Y').' selama '.LinkVerifikasi::HARI.' hari.'
            : 'Semua keluarga sudah punya link yang berlaku.');
    }

    /** Buat (atau buat ulang) link satu keluarga — link lama dicabut. */
    public function buat(Request $request, int $idWali): RedirectResponse
    {
        if (! LinkVerifikasi::aktif()) {
            return back()->with('error', self::MATI);
        }
        $data = $request->validate(['mulai' => ['required', 'date']]);
        $wali = Wali::where('status', 'aktif')->findOrFail($idWali);
        $this->link->buat($wali->id, Carbon::parse($data['mulai']), Auth::id());

        return back()->with('status', "Link verifikasi untuk keluarga {$wali->nama} dibuat.");
    }

    /** Buka WhatsApp dengan pesan + link yang sudah terisi, sekaligus menandai "terkirim". */
    public function kirimWa(int $id): RedirectResponse
    {
        if (! LinkVerifikasi::aktif()) {
            return back()->with('error', self::MATI);
        }
        $v = VerifikasiWali::with('wali')->whereNull('dicabut_pada')->findOrFail($id);
        $nomor = LinkVerifikasi::nomorWa($v->wali);
        if ($nomor === null) {
            return back()->with('error', "Nomor HP kontak utama keluarga {$v->wali->nama} kosong atau tak sah. Betulkan dulu di data Wali.");
        }
        $this->link->tandaiDikirim($v);

        return redirect()->away('https://wa.me/'.$nomor.'?text='.rawurlencode(LinkVerifikasi::pesanWa($v)));
    }

    public function cabut(int $id): RedirectResponse
    {
        $v = VerifikasiWali::with('wali')->whereNull('dicabut_pada')->findOrFail($id);
        $this->link->cabut($v);

        return back()->with('status', "Link verifikasi keluarga {$v->wali->nama} dicabut — link lama tak bisa dibuka lagi.");
    }
}
