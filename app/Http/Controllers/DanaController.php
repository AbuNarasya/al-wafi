<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\CoaDetail;
use App\Models\Dana;
use App\Services\Modules\DanaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Master Dana (wakaf, donasi berperuntukan, beasiswa, bantuan) + laporan
 * pertanggungjawabannya per donatur.
 */
class DanaController extends Controller
{
    public function __construct(private DanaService $service = new DanaService) {}

    public function index(Request $request): View
    {
        return view('dana.index', [
            'rows' => $this->service->daftar(
                trim((string) $request->query('q', '')) ?: null,
                trim((string) $request->query('jenis', '')) ?: null,
            ),
            'q' => trim((string) $request->query('q', '')),
            'jenis' => trim((string) $request->query('jenis', '')),
            'jenisOptions' => Dana::JENIS,
        ]);
    }

    public function create(): View
    {
        return view('dana.form', [
            'dana' => new Dana(['jenis' => 'tidak_terikat', 'status' => 'aktif', 'target_nominal' => 0]),
            'jenisOptions' => Dana::JENIS,
            'bebanOptions' => $this->bebanOptions(),
            'akunTerpilih' => [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $this->validasi($request, true);

        try {
            $dana = $this->service->simpan($d);
            $this->service->aturAkun($dana->kode_dana, $request->input('akun', []));
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('dana.index')->with('status', "Dana {$dana->kode_dana} ditambahkan.");
    }

    public function edit(string $kode): View
    {
        $dana = Dana::with('akun')->findOrFail($kode);

        return view('dana.form', [
            'dana' => $dana,
            'jenisOptions' => Dana::JENIS,
            'bebanOptions' => $this->bebanOptions(),
            'akunTerpilih' => $dana->akun->pluck('kode_coa')->all(),
        ]);
    }

    public function update(Request $request, string $kode): RedirectResponse
    {
        $d = $this->validasi($request, false);

        try {
            $this->service->simpan($d, $kode);
            $this->service->aturAkun($kode, $request->input('akun', []));
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('dana.index')->with('status', 'Dana diperbarui.');
    }

    public function destroy(string $kode): RedirectResponse
    {
        try {
            $this->service->hapus($kode);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('dana.index')->with('status', 'Dana dihapus.');
    }

    /** Laporan pertanggungjawaban dana — yang dikirim ke donatur. */
    public function laporan(Request $request): View
    {
        $from = $request->query('from') ?: null;
        $to = $request->query('to') ?: null;

        return view('dana.laporan', [
            'data' => $this->service->laporan($from, $to),
            'from' => $from, 'to' => $to,
        ]);
    }

    private function validasi(Request $request, bool $baru): array
    {
        return $request->validate([
            'kode_dana' => $baru
                ? ['required', 'string', 'max:50', 'regex:/^\S+$/', Rule::unique('dana', 'kode_dana')]
                : ['prohibited'],
            'nama_dana' => ['required', 'string', 'max:255'],
            'jenis' => ['required', Rule::in(array_keys(Dana::JENIS))],
            'donatur' => ['nullable', 'string', 'max:255'],
            'peruntukan' => ['nullable', 'string', 'max:2000'],
            'tanggal_mulai' => ['nullable', 'date'],
            'tanggal_selesai' => ['nullable', 'date', 'after_or_equal:tanggal_mulai'],
            'target_nominal' => ['nullable', 'numeric', 'min:0'],
            'status' => ['required', Rule::in(['aktif', 'nonaktif', 'selesai'])],
            'urutan' => ['nullable', 'integer', 'min:0'],
            'akun' => ['array'],
            'akun.*' => ['string', 'exists:coa_detail,kode_coa'],
        ]);
    }

    /**
     * Hanya akun BEBAN yang ditawarkan sebagai peruntukan: pembatasan dana
     * terikat mengatur BELANJA-nya, bukan tempat kasnya menumpang.
     *
     * @return array<string,string>
     */
    private function bebanOptions(): array
    {
        return CoaDetail::where('status', 'aktif')->orderBy('kode_coa')->get()
            ->filter(fn ($c) => CoaDetail::akarKelompok($c->kode_grup) === '5')
            ->mapWithKeys(fn ($c) => [$c->kode_coa => "{$c->kode_coa} — {$c->nama_coa}"])
            ->all();
    }
}
