<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\Jenjang;
use App\Models\Kelas;
use App\Models\Santri;
use App\Services\Modules\KelasService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Master Kelas (rombel) & Penempatan Kelas santri aktif — 21 Okt 2026. */
class KelasController extends Controller
{
    public function __construct(private readonly KelasService $kelas) {}

    public function index(Request $request): View
    {
        $jenjang = Jenjang::orderBy('urutan')->orderBy('kode')->get();
        $pilih = $request->query('jenjang') ?: $jenjang->first()?->kode;

        return view('kelas.index', [
            'jenjang' => $jenjang, 'pilih' => $pilih,
            'kelas' => Kelas::withCount('santri')->where('kode_jenjang', $pilih)->orderBy('tingkat')->orderBy('nama')->get(),
            'belum' => Santri::where('status', 'aktif')->whereNull('id_kelas')->where('kode_jenjang', $pilih)->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        try {
            $k = $this->kelas->simpan($this->isian($request));
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('kelas.index', ['jenjang' => $k->kode_jenjang])->with('status', "Kelas {$k->nama} ditambahkan.");
    }

    public function update(Request $request, Kelas $kelas): RedirectResponse
    {
        try {
            $this->kelas->simpan($this->isian($request), $kelas);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('kelas.index', ['jenjang' => $kelas->kode_jenjang])->with('status', "Kelas {$kelas->nama} disimpan.");
    }

    public function destroy(Kelas $kelas): RedirectResponse
    {
        try {
            $this->kelas->hapus($kelas);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "Kelas {$kelas->nama} dihapus.");
    }

    /** Daftar santri aktif satu jenjang + tingkat; yang belum berkelas lebih dulu (bawaan). */
    public function penempatan(Request $request): View
    {
        $jenjang = Jenjang::orderBy('urutan')->orderBy('kode')->get();
        $pilih = $request->query('jenjang') ?: $jenjang->first()?->kode;
        $tingkat = $request->query('tingkat');
        $semua = $request->query('tampil') === 'semua';

        $santri = Santri::with('kelas:id,nama')->where('status', 'aktif')->where('kode_jenjang', $pilih)
            ->when($tingkat !== null && $tingkat !== '', fn ($q) => $q->where('tingkat', (int) $tingkat))
            ->when(! $semua, fn ($q) => $q->whereNull('id_kelas'))
            ->orderBy('tingkat')->orderBy('jenis_kelamin')->orderBy('nama')->get(['id', 'nama', 'nis', 'jenis_kelamin', 'tingkat', 'id_kelas']);

        return view('kelas.penempatan', [
            'jenjang' => $jenjang, 'pilih' => $pilih, 'tingkat' => $tingkat, 'semua' => $semua, 'santri' => $santri,
            'kelas' => Kelas::where('kode_jenjang', $pilih)->where('status', 'aktif')
                ->when($tingkat !== null && $tingkat !== '', fn ($q) => $q->where('tingkat', (int) $tingkat))
                ->orderBy('tingkat')->orderBy('nama')->get(),
            'jumlahTingkat' => (int) ($jenjang->firstWhere('kode', $pilih)?->jumlah_tingkat ?? 0),
        ]);
    }

    public function tempatkan(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'santri' => ['required', 'array', 'min:1'], 'santri.*' => ['integer'],
            'id_kelas' => ['nullable', 'integer'],
        ], ['santri.required' => 'Centang minimal satu santri.']);
        try {
            $n = $this->kelas->tempatkan(array_map('intval', $data['santri']), $data['id_kelas'] ? (int) $data['id_kelas'] : null);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }
        $nama = $data['id_kelas'] ? Kelas::find($data['id_kelas'])?->nama : null;

        return back()->with('status', $nama ? "{$n} santri ditempatkan di kelas {$nama}." : "Kelas {$n} santri dikosongkan.");
    }

    private function isian(Request $request): array
    {
        return $request->validate([
            'kode_jenjang' => ['required', 'string', 'exists:jenjang,kode'],
            'tingkat' => ['required', 'integer', 'min:1', 'max:20'],
            'nama' => ['required', 'string', 'max:120'],
            'peruntukan' => ['required', Rule::in(array_keys(Kelas::PERUNTUKAN))],
            'status' => ['nullable', Rule::in(['aktif', 'nonaktif'])],
        ]);
    }
}
