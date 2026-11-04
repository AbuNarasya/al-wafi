<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\AturanPerizinanRutin;
use App\Models\JadwalPerizinanRutin;
use App\Models\Jenjang;
use App\Models\Perizinan;
use App\Services\Modules\PerizinanRutinService;
use App\Services\Modules\PerizinanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Perizinan Rutin (Penjengukan & Kepulangan) — sisi staf: rekap per jadwal, setujui massal, kelola jadwal & matriks. */
class PerizinanRutinController extends Controller
{
    public function __construct(private readonly PerizinanRutinService $svc) {}

    /** Daftar jadwal (yang akan datang + 30 hari terakhir) beserta hitungan pengajuannya. */
    public function index(Request $request): View
    {
        $lalu = $request->boolean('lalu');
        $jadwal = JadwalPerizinanRutin::with(['tipe', 'cakupan'])
            ->when($lalu, fn ($q) => $q->where('selesai', '<', now())->where('selesai', '>=', now()->subDays(90))->orderByDesc('mulai'),
                fn ($q) => $q->where('selesai', '>=', now())->orderBy('mulai'))
            ->limit(60)->get();
        $hitung = Perizinan::whereIn('id_jadwal', $jadwal->pluck('id'))->selectRaw('id_jadwal, status, count(*) as n')
            ->groupBy('id_jadwal', 'status')->get()->groupBy('id_jadwal');

        return view('perizinan-rutin.index', ['jadwal' => $jadwal, 'hitung' => $hitung, 'lalu' => $lalu]);
    }

    /** Rekap satu jadwal: santri dalam cakupan + status pengajuannya. */
    public function show(Request $request, JadwalPerizinanRutin $jadwal): View
    {
        $jadwal->load(['tipe', 'cakupan']);
        $status = (string) $request->query('status', '');
        $cari = trim((string) $request->query('q', ''));
        $izin = Perizinan::where('id_jadwal', $jadwal->id)->whereNotIn('status', ['dibatalkan'])->get()->keyBy('id_santri');
        $santri = $jadwal->santriQuery()->with(['kelas:id,nama', 'jenjang:kode,nama'])
            ->when($cari !== '', fn ($w) => $w->where(fn ($x) => $x->where('nama', 'ilike', "%{$cari}%")->orWhere('nis', 'ilike', "%{$cari}%")))
            ->orderBy('kode_jenjang')->orderBy('nama')->get(['id', 'nama', 'nis', 'kode_jenjang', 'jenis_kelamin', 'id_kelas']);
        $baris = $santri->map(fn ($s) => ['santri' => $s, 'izin' => $izin[$s->id] ?? null])
            ->when($status !== '', fn ($c) => $c->filter(fn ($b) => match ($status) {
                'belum' => $b['izin'] === null,
                'menunggu' => in_array($b['izin']?->status, Perizinan::MENUNGGU, true),
                default => $b['izin']?->status === $status,
            }))
            ->values();
        $menungguSaya = $izin->filter(fn ($z) => in_array($z->status, Perizinan::MENUNGGU, true) && PerizinanService::bolehMemutus($z))->count();

        return view('perizinan-rutin.show', ['j' => $jadwal, 'baris' => $baris, 'status' => $status, 'cari' => $cari,
            'jumlah' => ['santri' => $santri->count()] + $izin->countBy('status')->all(), 'menungguSaya' => $menungguSaya,
            'p' => PerizinanService::pengaturan()]);
    }

    public function setujuiSemua(Request $request, JadwalPerizinanRutin $jadwal): RedirectResponse
    {
        try {
            $n = $this->svc->setujuiSemua($jadwal, $request->user());
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "{$n} pengajuan disetujui.");
    }

    // ---- Kelola jadwal & matriks (hak perizinan-jadwal) ----

    public function jadwal(): View
    {
        return view('perizinan-rutin.jadwal', [
            'tipe' => PerizinanRutinService::tipeRutin(),
            'jenjang' => Jenjang::orderBy('urutan')->orderBy('kode')->get(['kode', 'nama']),
            'aturan' => AturanPerizinanRutin::all()->groupBy('kode_tipe')->map(fn ($c) => $c->keyBy(fn ($a) => $a->kode_jenjang.'|'.$a->jenis_kelamin)),
            'mendatang' => JadwalPerizinanRutin::with(['tipe', 'cakupan'])->withCount(['izin' => fn ($q) => $q->where('status', '!=', 'dibatalkan')])
                ->where('selesai', '>=', now())->orderBy('mulai')->limit(80)->get(),
        ]);
    }

    public function simpanAturan(Request $request, string $tipe): RedirectResponse
    {
        $request->validate(['sel' => ['nullable', 'array']]);

        return $this->coba(fn () => $this->svc->simpanAturan($tipe, $request->input('sel', [])), 'Matriks aturan disimpan. Tekan "Buat jadwal" untuk membangkitkan jadwalnya.');
    }

    public function bangkitkan(Request $request): RedirectResponse
    {
        $d = $request->validate(['dari' => ['required', 'date_format:Y-m'], 'bulan' => ['required', 'integer', 'between:1,12']]);
        try {
            $n = $this->svc->bangkitkan($d['dari'], (int) $d['bulan'], $request->user());
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', $n > 0 ? "{$n} jadwal baru dibuat. Periksa & geser bila ada libur/ujian." : 'Tidak ada jadwal baru — semuanya sudah ada.');
    }

    public function tambah(Request $request): RedirectResponse
    {
        $d = $this->isianJadwal($request) + $request->validate(['kode_tipe' => ['required', 'string']]);

        return $this->coba(fn () => $this->svc->tambah($d, $request->user()), 'Jadwal khusus ditambahkan.');
    }

    public function edit(JadwalPerizinanRutin $jadwal): View
    {
        return view('perizinan-rutin.edit', ['j' => $jadwal->load(['tipe', 'cakupan']),
            'jenjang' => Jenjang::orderBy('urutan')->orderBy('kode')->get(['kode', 'nama'])]);
    }

    public function update(Request $request, JadwalPerizinanRutin $jadwal): RedirectResponse
    {
        $d = $this->isianJadwal($request);
        try {
            $this->svc->ubah($jadwal, $d);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('perizinan_rutin.jadwal')->with('status', 'Jadwal diperbarui.');
    }

    public function tiadakan(Request $request, JadwalPerizinanRutin $jadwal): RedirectResponse
    {
        $d = $request->validate(['alasan' => ['required', 'string', 'max:500']], ['alasan.required' => 'Tulis alasan jadwal ditiadakan.']);

        return $this->coba(fn () => $this->svc->tiadakan($jadwal, $d['alasan']), 'Jadwal ditiadakan; wali dalam cakupan dikabari.');
    }

    public function aktifkan(JadwalPerizinanRutin $jadwal): RedirectResponse
    {
        return $this->coba(fn () => $this->svc->aktifkanLagi($jadwal), 'Jadwal diaktifkan lagi.');
    }

    public function destroy(JadwalPerizinanRutin $jadwal): RedirectResponse
    {
        return $this->coba(fn () => $this->svc->hapus($jadwal), 'Jadwal dihapus.');
    }

    private function isianJadwal(Request $request): array
    {
        return $request->validate([
            'mulai' => ['required', 'date'], 'selesai' => ['required', 'date'],
            'catatan' => ['nullable', 'string', 'max:500'], 'cakupan' => ['required', 'array', 'min:1'], 'cakupan.*' => ['string'],
        ], ['cakupan.required' => 'Pilih minimal satu jenjang & ikhwan/akhwat.']);
    }

    private function coba(\Closure $aksi, string $pesan): RedirectResponse
    {
        try {
            $aksi();
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('status', $pesan);
    }
}
