<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\JenisPakaianLaundry;
use App\Models\Laundry;
use App\Models\Santri;
use App\Services\Modules\KartuSantriService;
use App\Services\Modules\LaundryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Layar petugas LAUNDRY (tahap 1, 26 Okt 2026) — lihat App\Services\Modules\LaundryService. */
class LaundryController extends Controller
{
    public function __construct(private readonly LaundryService $svc) {}

    /** Papan proses: Diterima · Diproses · Selesai, plus yang diserahkan hari ini. */
    public function index(Request $request): View
    {
        $cari = trim((string) $request->query('cari', ''));
        $q = Laundry::with(['santri:id,nama,nis,kode_jenjang,id_kelas', 'santri.kelas:id,nama', 'item'])
            ->whereIn('status', ['diterima', 'diproses', 'selesai'])
            ->when($cari !== '', fn ($w) => $w->where(fn ($x) => $x->where('nomor', 'ilike', "%{$cari}%")
                ->orWhereHas('santri', fn ($s) => $s->where('nama', 'ilike', "%{$cari}%")->orWhere('nis', 'ilike', "%{$cari}%"))))
            ->orderBy('diterima_pada')->get();

        return view('laundry.index', [
            'cari' => $cari,
            'kolom' => collect(['diterima', 'diproses', 'selesai'])->mapWithKeys(fn ($s) => [$s => $q->where('status', $s)->values()]),
            'diserahkanHariIni' => Laundry::where('status', 'diserahkan')->whereDate('diserahkan_pada', today())->count(),
        ]);
    }

    /** Penerimaan — langkah 1: tap kartu (atau cari nama/NIS bila kartu tertinggal); langkah 2: formulir. */
    public function terima(Request $request): View
    {
        [$santri, $cara, $galat, $hasilCari] = $this->kenali($request);

        return view('laundry.terima', [
            'santri' => $santri, 'cara' => $cara, 'galat' => $galat, 'hasilCari' => $hasilCari, 'uid' => $request->query('kartu'),
            'jenis' => JenisPakaianLaundry::where('aktif', true)->orderBy('nama')->get(['id', 'nama']),
            'berjalan' => $santri ? Laundry::where('id_santri', $santri->id)->whereIn('status', ['diterima', 'diproses', 'selesai'])->orderBy('diterima_pada')->get() : collect(),
            // Kuota bulan ini (tahap 2) — perkiraan kelebihan dihitung ulang di layar saat berat diketik.
            'kuota' => $santri ? (new \App\Services\Modules\TagihanLaundryService)->ringkas($santri) : null,
        ]);
    }

    public function simpanTerima(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'berat' => ['required', 'numeric', 'gt:0', 'max:'.LaundryService::BERAT_MAKS],
            'catatan' => ['nullable', 'string', 'max:1000'],
            'item' => ['required', 'array', 'max:60'], 'item.*.id_jenis_pakaian' => ['nullable', 'integer'], 'item.*.jumlah' => ['nullable', 'integer', 'min:0', 'max:200'],
        ]);
        [$santri, $cara, $galat] = $this->kenali($request);
        if (! $santri) {
            return redirect()->route('laundry.terima')->with('error', $galat ?? 'Santri tidak dikenali — tap ulang kartunya.');
        }
        try {
            $l = $this->svc->terima($santri, (string) $d['berat'], $d['item'], $d['catatan'] ?? null, $cara, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('laundry.terima')->with('status', "{$l->nomor} — cucian {$santri->nama} ({$l->berat} kg, {$l->jumlahPotong()} potong) diterima. Siap untuk santri berikutnya.");
    }

    public function majukan(Request $request): RedirectResponse
    {
        $d = $request->validate(['dari' => ['required', Rule::in(array_keys(Laundry::MAJU))], 'id' => ['required', 'array', 'min:1'], 'id.*' => ['integer']],
            ['id.required' => 'Pilih minimal satu kantong.']);
        try {
            $h = $this->svc->majukan($d['id'], $d['dari'], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }
        $ke = Laundry::LABEL[Laundry::MAJU[$d['dari']]];

        return back()->with('status', "{$h['dimajukan']} kantong → {$ke}.".($h['dilewati'] ? " {$h['dilewati']} dilewati (statusnya sudah berubah)." : ''));
    }

    /** Penyerahan: tap kartu → kantong yang Selesai ditampilkan beserta daftar pakaiannya untuk dicocokkan. */
    public function serahkan(Request $request): View
    {
        [$santri, $cara, $galat, $hasilCari] = $this->kenali($request);

        return view('laundry.serahkan', ['santri' => $santri, 'cara' => $cara, 'galat' => $galat, 'hasilCari' => $hasilCari,
            'uid' => $request->query('kartu'), 'kantong' => $santri ? $this->svc->untukDiserahkan($santri) : collect()]);
    }

    public function simpanSerahkan(Request $request): RedirectResponse
    {
        $d = $request->validate(['id' => ['required', 'array', 'min:1'], 'id.*' => ['integer'], 'kembali' => ['nullable', 'array'],
            'kembali.*' => ['integer', 'min:0'], 'catatan_serah' => ['nullable', 'string', 'max:1000']], ['id.required' => 'Pilih kantong yang diserahkan.']);
        [$santri, $cara, $galat] = $this->kenali($request);
        if (! $santri) {
            return redirect()->route('laundry.serahkan')->with('error', $galat ?? 'Santri tidak dikenali — tap ulang kartunya.');
        }
        try {
            $n = $this->svc->serahkan($santri, array_map('intval', $d['id']), $d['kembali'] ?? [], $d['catatan_serah'] ?? null, $cara, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('laundry.serahkan')->with('status', "{$n} kantong diserahkan kepada {$santri->nama}.");
    }

    public function show(Laundry $laundry): View
    {
        $laundry->load(['santri.kelas', 'santri.jenjang', 'item']);

        return view('laundry.show', ['l' => $laundry,
            'terkunci' => (new \App\Services\Modules\TagihanLaundryService)->terkunci($laundry->id_santri, $laundry->diterima_pada->format('Y-m')),
            'petugas' => \App\Models\User::whereIn('id_pengguna', array_filter(array_map(fn ($s) => $laundry->{"{$s}_oleh"}, [...array_keys(Laundry::LANGKAH), 'dibatalkan'])))
                ->pluck('nama', 'id_pengguna')]);
    }

    public function batal(Request $request, Laundry $laundry): RedirectResponse
    {
        $d = $request->validate(['alasan' => ['required', 'string', 'max:500']]);
        try {
            $this->svc->batalkan($laundry, $d['alasan'], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "{$laundry->nomor} dibatalkan.");
    }

    public function koreksiBerat(Request $request, Laundry $laundry): RedirectResponse
    {
        $d = $request->validate(['berat' => ['required', 'numeric', 'gt:0', 'max:'.LaundryService::BERAT_MAKS], 'alasan' => ['required', 'string', 'max:500']]);
        try {
            $this->svc->koreksiBerat($laundry, (string) $d['berat'], $d['alasan'], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Berat dikoreksi.');
    }

    public function riwayat(Request $request): View
    {
        $dari = Carbon::parse($request->query('dari', now()->startOfMonth()->toDateString()))->startOfDay();
        $sampai = Carbon::parse($request->query('sampai', now()->toDateString()))->endOfDay();
        $status = $request->query('status');
        $cari = trim((string) $request->query('cari', ''));
        $q = Laundry::with(['santri:id,nama,nis', 'item'])->whereBetween('diterima_pada', [$dari, $sampai])
            ->when(isset(Laundry::LABEL[$status]), fn ($w) => $w->where('status', $status))
            ->when($request->boolean('kurang'), fn ($w) => $w->where('ada_kurang', true))
            ->when($cari !== '', fn ($w) => $w->where(fn ($x) => $x->where('nomor', 'ilike', "%{$cari}%")
                ->orWhereHas('santri', fn ($s) => $s->where('nama', 'ilike', "%{$cari}%")->orWhere('nis', 'ilike', "%{$cari}%"))));

        return view('laundry.riwayat', ['dari' => $dari, 'sampai' => $sampai, 'status' => $status, 'cari' => $cari,
            'ringkas' => ['kantong' => (clone $q)->count(), 'berat' => (float) (clone $q)->sum('berat')],
            'rows' => $q->orderByDesc('diterima_pada')->paginate(50)->withQueryString()]);
    }

    // ---- Master jenis pakaian ----

    public function jenisPakaian(): View
    {
        return view('laundry.jenis-pakaian', ['rows' => JenisPakaianLaundry::orderByDesc('aktif')->orderBy('nama')->get(),
            'pemakaian' => \App\Models\LaundryItem::selectRaw('id_jenis_pakaian, count(*) n')->groupBy('id_jenis_pakaian')->pluck('n', 'id_jenis_pakaian')]);
    }

    public function simpanJenis(Request $request): RedirectResponse
    {
        $d = $request->validate(['nama' => ['required', 'string', 'max:100', 'unique:jenis_pakaian_laundry,nama']],
            ['nama.unique' => 'Jenis pakaian itu sudah ada.']);
        JenisPakaianLaundry::create(['nama' => trim($d['nama']), 'aktif' => true]);

        return back()->with('status', 'Jenis pakaian ditambahkan.');
    }

    public function ubahJenis(Request $request, int $id): RedirectResponse
    {
        $j = JenisPakaianLaundry::findOrFail($id);
        $d = $request->validate(['nama' => ['required', 'string', 'max:100', Rule::unique('jenis_pakaian_laundry', 'nama')->ignore($j->id)], 'aktif' => ['nullable', 'boolean']],
            ['nama.unique' => 'Jenis pakaian itu sudah ada.']);
        $j->update(['nama' => trim($d['nama']), 'aktif' => $request->boolean('aktif')]);

        return back()->with('status', 'Jenis pakaian diperbarui.');
    }

    /**
     * Santri dikenali dari KARTU yang ditap (`kartu`), atau — bila kartu tertinggal — dari pilihan hasil pencarian
     * (`santri`, dicatat sebagai cara "manual" supaya bisa diaudit). `cari` = daftar santri aktif yang cocok.
     *
     * @return array{0:?Santri, 1:?string, 2:?string, 3:\Illuminate\Support\Collection}
     */
    private function kenali(Request $request): array
    {
        $hasil = collect();
        $uid = trim((string) $request->input('kartu', ''));
        if ($uid !== '') {
            $kartu = (new KartuSantriService)->cariAktif($uid);
            if (! $kartu) {
                return [null, null, 'Kartu tidak dikenal atau diblokir. Kartu tertinggal? Cari santri dengan nama / NIS di bawah.', $hasil];
            }
            if ($kartu->santri?->status !== 'aktif') {
                return [null, null, "{$kartu->santri?->nama} bukan santri aktif.", $hasil];
            }

            return [$kartu->santri->load(['kelas:id,nama', 'jenjang:kode,nama']), 'kartu', null, $hasil];
        }
        if ($id = (int) $request->input('santri', 0)) {
            $s = Santri::with(['kelas:id,nama', 'jenjang:kode,nama'])->where('status', 'aktif')->find($id);

            return [$s, $s ? 'manual' : null, $s ? null : 'Santri tidak ditemukan atau bukan santri aktif.', $hasil];
        }
        $cari = trim((string) $request->query('cari', ''));
        if (mb_strlen($cari) >= 3) {
            $hasil = Santri::with('kelas:id,nama')->where('status', 'aktif')
                ->where(fn ($w) => $w->where('nama', 'ilike', "%{$cari}%")->orWhere('nis', 'ilike', "%{$cari}%"))
                ->orderBy('nama')->limit(15)->get(['id', 'nama', 'nis', 'id_kelas']);
        }

        return [null, null, null, $hasil];
    }
}
