<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\Gelombang;
use App\Services\Modules\GelombangService;
use App\Services\Modules\TahunAjaranService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Master Gelombang (identitas & waktu) + MATRIKS potongannya.
 *
 * Dipisah dari besarannya karena periode & masa berlaku adalah sifat
 * gelombangnya: disimpan sekali, bukan diulang di tiap jenjang.
 */
class GelombangController extends Controller
{
    public function __construct(private readonly GelombangService $service = new GelombangService) {}

    public function index(Request $request): View
    {
        $ta = $this->tahunAjaran($request);

        return view('gelombang.index', [
            'ta' => $ta,
            'opsiTa' => (new TahunAjaranService)->opsiAktif(),
            'rows' => $this->service->daftar($ta),
        ]);
    }

    public function create(Request $request): View
    {
        return view('gelombang.form', [
            'row' => new Gelombang(['tahun_ajaran' => $this->tahunAjaran($request), 'masa_berlaku_hari' => 7, 'status' => 'aktif']),
            'baru' => true,
            'opsiTa' => (new TahunAjaranService)->opsiAktif(),
        ]);
    }

    public function edit(int $id): View
    {
        try {
            $row = $this->service->get($id);
        } catch (AppException $e) {
            abort($e->status, $e->getMessage());
        }

        return view('gelombang.form', ['row' => $row, 'baru' => false, 'opsiTa' => (new TahunAjaranService)->opsiAktif()]);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->simpan($request, null);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        return $this->simpan($request, $id);
    }

    private function simpan(Request $request, ?int $id): RedirectResponse
    {
        $data = $request->validate([
            'tahun_ajaran' => ['required', 'string', 'exists:tahun_ajaran,kode'],
            'kode' => ['required', 'string', 'max:50', 'not_in:'.SantriController::TANPA_GELOMBANG],
            'nama' => ['nullable', 'string', 'max:255'],
            'berlaku_mulai' => ['nullable', 'date'],
            'berlaku_sampai' => ['nullable', 'date', 'after_or_equal:berlaku_mulai'],
            'masa_berlaku_hari' => ['required', 'integer', 'min:1'],
            'status' => ['required', 'in:aktif,arsip'],
            'keterangan' => ['nullable', 'string'],
        ], [
            'berlaku_sampai.after_or_equal' => 'Tanggal selesai periode tidak boleh mendahului tanggal mulai.',
            'kode.not_in' => 'Kode "'.SantriController::TANPA_GELOMBANG.'" dipakai sebagai penanda "Tanpa Gelombang" di form registrasi.',
        ]);
        // Isian nullable yang tak dikirim tidak muncul sebagai kunci.
        foreach (['berlaku_mulai', 'berlaku_sampai', 'nama', 'keterangan'] as $k) {
            $data[$k] = $data[$k] ?? null;
        }

        try {
            $row = $this->service->simpanMaster($data, $id);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('gelombang.index', ['ta' => $row->tahun_ajaran])
            ->with('status', $id ? 'Gelombang berhasil diperbarui.' : 'Gelombang berhasil ditambahkan.');
    }

    public function destroy(int $id): RedirectResponse
    {
        try {
            $this->service->hapus($id);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('gelombang.index')->with('status', 'Gelombang berhasil dihapus.');
    }

    // ---- Matriks potongan ----

    public function potongan(Request $request): View
    {
        $ta = $this->tahunAjaran($request);

        return view('gelombang.potongan', [
            'ta' => $ta,
            'opsiTa' => (new TahunAjaranService)->opsiAktif(),
            'grid' => $ta ? $this->service->grid($ta) : null,
        ]);
    }

    public function simpanPotongan(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tahun_ajaran' => ['required', 'string', 'exists:tahun_ajaran,kode'],
            'sel' => ['nullable', 'array'],
        ]);

        try {
            $n = $this->service->simpanGrid($data['tahun_ajaran'], $data['sel'] ?? []);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('gelombang.potongan', ['ta' => $data['tahun_ajaran']])
            ->with('status', "Potongan gelombang disimpan ({$n} sel).");
    }

    /** T.A yang sedang dilihat: dari query, atau default pendaftaran. */
    private function tahunAjaran(Request $request): ?string
    {
        $opsi = (new TahunAjaranService)->opsiAktif();
        $ta = (string) $request->query('ta', '');

        return isset($opsi[$ta]) ? $ta : ((new TahunAjaranService)->defaultPendaftaran()?->kode ?? array_key_first($opsi));
    }
}
