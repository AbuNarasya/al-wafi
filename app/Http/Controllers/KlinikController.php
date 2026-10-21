<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\JenisBiaya;
use App\Models\KunjunganKlinik;
use App\Models\Santri;
use App\Services\Modules\KartuSantriService;
use App\Services\Modules\KlinikService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Layar petugas KLINIK (21 Okt 2026): hari ini, catat kunjungan, detail, riwayat santri, rekap. */
class KlinikController extends Controller
{
    public function __construct(private readonly KlinikService $svc) {}

    public function index(Request $request): View
    {
        $tgl = $request->query('tanggal') ? Carbon::parse($request->query('tanggal')) : today();

        return view('klinik.index', [
            'tanggal' => $tgl,
            'dirawat' => KunjunganKlinik::with('santri:id,nama,nis')->where('status', 'dirawat')->orderBy('datang_pada')->get(),
            'kunjungan' => KunjunganKlinik::with(['santri:id,nama,nis,kode_jenjang,id_kelas', 'santri.kelas:id,nama'])
                ->whereDate('datang_pada', $tgl->toDateString())->orderByDesc('datang_pada')->get(),
        ]);
    }

    /**
     * Langkah 1: santri TAP KARTU RFID (keputusan user 21 Okt 2026 — tak ada pencarian nama/NIS, supaya catatan
     * benar-benar milik santri yang datang). Langkah 2: formulir untuk santri pemilik kartu.
     */
    public function create(Request $request): View
    {
        $uid = trim((string) $request->query('kartu', ''));
        $santri = null;
        $galat = null;
        if ($uid !== '') {
            $kartu = (new KartuSantriService)->cariAktif($uid);
            if (! $kartu) {
                $galat = 'Kartu tidak dikenal atau diblokir. Santri yang belum punya kartu aktif dipasangkan kartu dulu di menu Kartu Santri (RFID).';
            } elseif ($kartu->santri?->status !== 'aktif') {
                $galat = "{$kartu->santri?->nama} bukan santri aktif.";
            } else {
                $santri = $kartu->santri->load(['kelas:id,nama', 'jenjang:kode,nama']);
            }
        }

        return view('klinik.create', ['santri' => $santri, 'galat' => $galat, 'uid' => $santri ? KartuSantriService::bersihkanUid($uid) : null,
            'riwayat' => $santri ? KunjunganKlinik::where('id_santri', $santri->id)->orderByDesc('datang_pada')->limit(5)->get() : collect()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'kartu' => ['required', 'string', 'max:80'], 'keluhan' => ['required', 'string', 'max:2000'],
            'suhu' => ['nullable', 'numeric', 'between:30,45'], 'tensi' => ['nullable', 'string', 'max:15'],
            'nadi' => ['nullable', 'integer', 'between:20,250'], 'berat' => ['nullable', 'numeric', 'between:5,250'],
            'pemeriksaan' => ['nullable', 'string', 'max:3000'], 'tindakan' => ['nullable', 'string', 'max:3000'],
            'tindak_lanjut' => ['required', Rule::in(array_keys(KunjunganKlinik::TINDAK_LANJUT))], 'rujukan_ke' => ['nullable', 'string', 'max:255'],
            'obat' => ['nullable', 'array', 'max:20'], 'obat.*.nama' => ['nullable', 'string', 'max:255'],
            'obat.*.dosis' => ['nullable', 'string', 'max:255'], 'obat.*.jumlah' => ['nullable', 'string', 'max:50'],
        ]);
        // Santri ditentukan dari KARTU yang ditap, bukan id kiriman formulir.
        $kartu = (new KartuSantriService)->cariAktif($d['kartu']);
        if (! $kartu) {
            return redirect()->route('klinik.create')->with('error', 'Kartu tidak dikenal atau diblokir — tap ulang kartu santri.');
        }
        $d['id_santri'] = $kartu->id_santri;
        try {
            $k = $this->svc->catat($d, $d['obat'] ?? [], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('klinik.show', $k->id)->with('status', "Kunjungan {$k->nomor} dicatat. Wali sudah dikabari.");
    }

    public function show(KunjunganKlinik $kunjungan): View
    {
        return view('klinik.show', [
            'k' => $kunjungan->load(['santri.kelas', 'santri.jenjang', 'obat', 'tagihan', 'pencatat']),
            'jenisBiaya' => JenisBiaya::where('status', 'aktif')->orderBy('nama')->get()
                ->filter(fn ($j) => \App\Models\TipeBiaya::perilakuDari($j->tipe) === 'lain' && ! $j->berulang)->pluck('nama', 'kode'),
        ]);
    }

    public function selesai(Request $request, KunjunganKlinik $kunjungan): RedirectResponse
    {
        $d = $request->validate(['catatan' => ['nullable', 'string', 'max:2000']]);
        try {
            $this->svc->selesaikan($kunjungan, $d['catatan'] ?? null, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Rawat di klinik selesai. Wali sudah dikabari.');
    }

    public function tagihan(Request $request, KunjunganKlinik $kunjungan): RedirectResponse
    {
        abort_unless(\App\Support\Akses::boleh('tagihan-lain', 'buat'), 403, 'Menerbitkan tagihan butuh hak buat di Tagihan Lain-lain.');
        $d = $request->validate(['kode_jenis' => ['required', 'string'], 'nominal' => ['required', 'numeric', 'min:1'], 'keterangan' => ['nullable', 'string', 'max:255']]);
        try {
            $t = $this->svc->terbitkanTagihan($kunjungan, $d['kode_jenis'], $d['nominal'], $d['keterangan'] ?? null, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Tagihan Rp '.number_format((float) $t->nominal, 0, ',', '.').' terbit ke wali.');
    }

    public function riwayat(int $id): View
    {
        $s = Santri::with(['kelas:id,nama', 'jenjang:kode,nama'])->findOrFail($id);

        return view('klinik.riwayat', ['santri' => $s,
            'kunjungan' => KunjunganKlinik::with('obat')->where('id_santri', $s->id)->orderByDesc('datang_pada')->paginate(30)]);
    }

    public function rekap(Request $request): View
    {
        $dari = Carbon::parse($request->query('dari', now()->startOfMonth()->toDateString()))->startOfDay();
        $sampai = Carbon::parse($request->query('sampai', now()->toDateString()))->endOfDay();
        $rows = KunjunganKlinik::whereBetween('datang_pada', [$dari, $sampai])->get(['keluhan', 'tindak_lanjut', 'id_santri']);

        return view('klinik.rekap', [
            'dari' => $dari, 'sampai' => $sampai, 'jumlah' => $rows->count(), 'santri' => $rows->pluck('id_santri')->unique()->count(),
            'tindakLanjut' => $rows->countBy('tindak_lanjut'),
            // Keluhan terbanyak: kata kunci pertama keluhan (huruf kecil) — cukup untuk melihat pola (demam, batuk, …).
            'keluhan' => $rows->map(fn ($r) => mb_strtolower(strtok(trim($r->keluhan), " ,.;")) ?: '-')->countBy()->sortDesc()->take(10),
        ]);
    }
}
