<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\Perizinan;
use App\Models\Santri;
use App\Models\TipeIzin;
use App\Services\Modules\KartuSantriService;
use App\Services\Modules\PerizinanService;
use App\Support\Unggahan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Perizinan Insidental — sisi staf (daftar, putuskan, atas nama wali, pos keluar/masuk, pengaturan). Lihat PerizinanService. */
class PerizinanController extends Controller
{
    public const TAB = ['menunggu' => 'Menunggu persetujuan', 'disetujui' => 'Disetujui (belum keluar)', 'keluar' => 'Sedang di luar', 'riwayat' => 'Riwayat'];

    public function __construct(private readonly PerizinanService $svc) {}

    public function index(Request $request): View
    {
        $tab = isset(self::TAB[$request->query('tab')]) ? $request->query('tab') : 'menunggu';
        $cari = trim((string) $request->query('q', ''));
        $tipe = $request->query('tipe');
        $jk = in_array($request->query('jk'), ['L', 'P'], true) ? $request->query('jk') : null;
        $dasar = fn () => Perizinan::query()
            ->when($cari !== '', fn ($w) => $w->where(fn ($x) => $x->where('nomor', 'ilike', "%{$cari}%")
                ->orWhereHas('santri', fn ($s) => $s->where('nama', 'ilike', "%{$cari}%")->orWhere('nis', 'ilike', "%{$cari}%"))))
            ->when($tipe, fn ($w) => $w->where('kode_tipe', $tipe))
            ->when($jk, fn ($w) => $w->whereHas('santri', fn ($s) => $s->where('jenis_kelamin', $jk)));
        $status = match ($tab) {
            'menunggu' => Perizinan::MENUNGGU, 'disetujui' => ['disetujui'], 'keluar' => ['keluar'], default => ['kembali', 'ditolak', 'dibatalkan'],
        };

        return view('perizinan.index', [
            'tab' => $tab, 'cari' => $cari, 'tipe' => $tipe, 'jk' => $jk,
            'jumlah' => collect(self::TAB)->map(fn ($l, $k) => $k === 'riwayat' ? null
                : $dasar()->whereIn('status', match ($k) { 'menunggu' => Perizinan::MENUNGGU, default => [$k] })->count()),
            'terlambat' => Perizinan::where('status', 'keluar')->where('rencana_kembali', '<', now())->count(),
            'rows' => $dasar()->with(['santri:id,nama,nis,id_kelas,jenis_kelamin', 'santri.kelas:id,nama', 'tipe'])->whereIn('status', $status)
                ->orderBy($tab === 'riwayat' ? 'updated_at' : 'rencana_keluar', $tab === 'riwayat' ? 'desc' : 'asc')->paginate(50)->withQueryString(),
            'opsiTipe' => TipeIzin::orderBy('urutan')->pluck('nama', 'kode'),
            'p' => PerizinanService::pengaturan(),
        ]);
    }

    public function show(Perizinan $perizinan): View
    {
        $perizinan->load(['santri.kelas', 'santri.jenjang', 'santri.wali', 'tipe']);
        $id = array_filter(array_map(fn ($l) => $perizinan->{"{$l}_oleh"}, ['tingkat1', 'tingkat2', 'ditolak', 'keluar', 'kembali']));

        return view('perizinan.show', ['z' => $perizinan, 'p' => PerizinanService::pengaturan(),
            'petugas' => \App\Models\User::whereIn('id_pengguna', [...$id, $perizinan->diajukan_oleh ?? 0])->pluck('nama', 'id_pengguna'),
            'bolehMemutus' => PerizinanService::bolehMemutus($perizinan)]);
    }

    public function setujui(Request $request, Perizinan $perizinan): RedirectResponse
    {
        $d = $request->validate(['catatan' => ['nullable', 'string', 'max:1000']]);

        return $this->coba(fn () => $this->svc->setujui($perizinan, $request->user(), $d['catatan'] ?? null), "{$perizinan->nomor} disetujui.");
    }

    public function tolak(Request $request, Perizinan $perizinan): RedirectResponse
    {
        $d = $request->validate(['alasan' => ['required', 'string', 'max:1000']], ['alasan.required' => 'Tulis alasan penolakan.']);

        return $this->coba(fn () => $this->svc->tolak($perizinan, $request->user(), $d['alasan']), "{$perizinan->nomor} ditolak.");
    }

    public function lampiran(Perizinan $perizinan)
    {
        abort_unless($perizinan->lampiran_path, 404);

        return Unggahan::sajikan('local', $perizinan->lampiran_path, $perizinan->lampiran_nama);
    }

    // ---- Atas nama wali ----

    public function buat(): View
    {
        return view('perizinan.buat', [
            'tipe' => TipeIzin::where('aktif', true)->orderBy('urutan')->get(),
            'santriOptions' => Santri::where('status', 'aktif')->orderBy('nama')->get(['id', 'nama', 'nis'])
                ->mapWithKeys(fn ($s) => [$s->id => trim("{$s->nama} ({$s->nis})", ' ()')])->all(),
        ]);
    }

    public function simpan(Request $request): RedirectResponse
    {
        $d = $request->validate(self::aturan() + ['id_santri' => ['required', 'integer', 'exists:santri,id']]);
        try {
            $z = $this->svc->ajukan(Santri::findOrFail($d['id_santri']), $d, staf: $request->user(), lampiran: $request->file('lampiran'));
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('perizinan.show', $z->id)->with('status', "{$z->nomor} dicatat — menunggu persetujuan.");
    }

    /** @return array<string,list<string>> Aturan isian pengajuan (dipakai juga portal wali). */
    public static function aturan(): array
    {
        return [
            'kode_tipe' => ['required', 'string', 'exists:tipe_izin,kode'],
            'keperluan' => ['nullable', 'string', 'max:1000'],
            'rencana_keluar' => ['required', 'date'], 'rencana_kembali' => ['required', 'date'],
            'penjemput_nama' => ['nullable', 'string', 'max:255'], 'penjemput_hubungan' => ['nullable', 'string', 'max:100'],
            'penjemput_hp' => ['nullable', 'string', 'regex:/^[0-9+\-\s()]{8,20}$/'],
            'lampiran' => Unggahan::aturan(wajib: false),
        ];
    }

    // ---- Pos keluar / masuk ----

    public function pos(Request $request): View
    {
        [$santri, $cara, $galat, $hasil] = $this->kenali($request);

        return view('perizinan.pos', ['santri' => $santri, 'cara' => $cara, 'galat' => $galat, 'hasilCari' => $hasil,
            'uid' => $request->query('kartu'), 'izin' => $santri ? $this->svc->untukPos($santri) : collect()]);
    }

    public function catatPos(Request $request, Perizinan $perizinan): RedirectResponse
    {
        $d = $request->validate(['aksi' => ['required', 'in:keluar,kembali'], 'cara' => ['required', 'in:kartu,manual']]);
        try {
            $d['aksi'] === 'keluar'
                ? $this->svc->catatKeluar($perizinan, $request->user(), $d['cara'])
                : $this->svc->catatKembali($perizinan, $request->user(), $d['cara']);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }
        $perizinan->loadMissing('santri:id,nama');

        return redirect()->route('perizinan.pos')->with('status', "{$perizinan->santri->nama} tercatat ".($d['aksi'] === 'keluar' ? 'KELUAR' : 'KEMBALI').'. Siap untuk santri berikutnya.');
    }

    /** Santri dikenali dari kartu RFID yang ditap, atau dicari nama/NIS (kartu tertinggal → cara "manual"). */
    private function kenali(Request $request): array
    {
        $uid = trim((string) $request->query('kartu', ''));
        if ($uid !== '') {
            $kartu = (new KartuSantriService)->cariAktif($uid);
            if (! $kartu || $kartu->santri?->status !== 'aktif') {
                return [null, null, 'Kartu tidak dikenal, diblokir, atau bukan santri aktif. Kartu tertinggal? Cari nama / NIS di bawah.', collect()];
            }

            return [$kartu->santri->load(['kelas:id,nama']), 'kartu', null, collect()];
        }
        if ($id = (int) $request->query('santri', 0)) {
            $s = Santri::with('kelas:id,nama')->where('status', 'aktif')->find($id);

            return [$s, $s ? 'manual' : null, $s ? null : 'Santri tidak ditemukan.', collect()];
        }
        $cari = trim((string) $request->query('cari', ''));
        $hasil = mb_strlen($cari) >= 3
            ? Santri::with('kelas:id,nama')->where('status', 'aktif')->where(fn ($w) => $w->where('nama', 'ilike', "%{$cari}%")->orWhere('nis', 'ilike', "%{$cari}%"))
                ->orderBy('nama')->limit(15)->get(['id', 'nama', 'nis', 'id_kelas'])
            : collect();

        return [null, null, null, $hasil];
    }

    // ---- Pengaturan ----

    public function pengaturan(): View
    {
        return view('perizinan.pengaturan', ['p' => PerizinanService::pengaturan(), 'tipe' => TipeIzin::orderBy('urutan')->get()]);
    }

    public function simpanPengaturan(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'label_tingkat1' => ['required', 'string', 'max:100'], 'label_tingkat2' => ['required', 'string', 'max:100'],
            'tipe' => ['nullable', 'array'], 'tipe.*.batas_jam' => ['nullable', 'integer', 'between:0,720'],
        ]);
        if (! $request->boolean('tingkat1_aktif') && ! $request->boolean('tingkat2_aktif')) {
            return back()->withInput()->with('error', 'Aktifkan minimal satu tingkat persetujuan.');
        }
        DB::transaction(function () use ($d, $request) {
            DB::table('pengaturan_perizinan')->updateOrInsert(['id' => 1], [
                'tingkat1_aktif' => $request->boolean('tingkat1_aktif'), 'tingkat2_aktif' => $request->boolean('tingkat2_aktif'),
                'label_tingkat1' => trim($d['label_tingkat1']), 'label_tingkat2' => trim($d['label_tingkat2']),
                'diubah_oleh' => $request->user()->id_pengguna, 'updated_at' => now(),
            ]);
            foreach (TipeIzin::all() as $t) {
                $t->update(['aktif' => $request->boolean("tipe.{$t->kode}.aktif"), 'batas_jam' => (int) ($d['tipe'][$t->kode]['batas_jam'] ?? 0)]);
            }
        });

        return back()->with('status', 'Pengaturan perizinan disimpan.');
    }

    private function coba(\Closure $aksi, string $pesan): RedirectResponse
    {
        try {
            $aksi();
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', $pesan);
    }
}
