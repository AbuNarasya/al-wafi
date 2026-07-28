<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\CompanySettings;
use App\Models\TagihanSantri;
use App\Services\Modules\AngsuranUangPangkalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Angsuran Uang Pangkal (rencana termin ber-versi + reminder + potongan
 * gelombang). Rencana TIDAK berjurnal; hanya PembayaranSantri berjurnal.
 * Controller tipis → AngsuranUangPangkalService.
 */
class AngsuranUangPangkalController extends Controller
{
    public function __construct(private readonly AngsuranUangPangkalService $service) {}

    public function index(Request $request): View
    {
        // Pilihan & default dropdown filter dari modul Setting Filter Termin.
        $filter = new \App\Services\Modules\TerminFilterService;
        $opsiHari = $filter->opsi();
        $dalamHari = $filter->dalamHari($request->query('dalam_hari'));

        return view('angsuran-uang-pangkal.index', [
            'rows' => $this->service->list(),
            'jatuhTempo' => $this->service->jatuhTempo($dalamHari),
            'potonganTempo' => $this->service->potonganJatuhTempo($dalamHari),
            'dalamHari' => $dalamHari,
            'opsiHari' => $opsiHari,
        ]);
    }

    public function create(): View
    {
        $tagihan = TagihanSantri::query()->with('santri')
            ->whereHas('jenis', fn ($q) => $q->whereIn('tipe', \App\Models\TipeBiaya::kode('uang_pangkal')))
            ->whereDoesntHave('rencanaAngsuran', fn ($q) => $q->where('status', 'aktif'))
            ->get();

        $santriData = $tagihan->map(fn ($t) => [
            'id_santri' => $t->id_santri, 'nama' => $t->santri?->nama, 'total' => (float) $t->nominal,
        ])->values();

        return view('angsuran-uang-pangkal.create', ['santriData' => $santriData]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validasiTermin($request);

        try {
            $this->service->buatRencana((int) $data['id_santri'], [
                'disepakati_pada' => $data['disepakati_pada'], 'catatan' => $data['catatan'] ?? null,
                'termin' => array_values($data['termin']),
            ], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('angsuran_uang_pangkal.index')->with('status', 'Rencana angsuran berhasil dibuat.');
    }

    /** Detail rencana satu santri (termin, potongan, riwayat bayar & versi) + form re-negosiasi. */
    public function show(int $idSantri): View
    {
        try {
            $detail = $this->service->detail($idSantri);
        } catch (AppException $e) {
            return abort(404, $e->getMessage());
        }

        return view('angsuran-uang-pangkal.show', ['d' => $detail]);
    }

    public function renegosiasi(Request $request, int $idSantri): RedirectResponse
    {
        $data = $request->validate([
            'disepakati_pada' => ['required', 'date'],
            'alasan' => ['required', 'string', 'max:255'],
            'catatan' => ['nullable', 'string'],
            'termin' => ['required', 'array', 'min:1'],
            'termin.*.nominal' => ['required', 'numeric', 'gt:0'],
            'termin.*.jatuh_tempo' => ['required', 'date'],
            'termin.*.keterangan' => ['nullable', 'string'],
        ]);

        try {
            $this->service->renegosiasi($idSantri, [
                'disepakati_pada' => $data['disepakati_pada'], 'alasan' => $data['alasan'],
                'catatan' => $data['catatan'] ?? null, 'termin' => array_values($data['termin']),
            ], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('angsuran_uang_pangkal.show', $idSantri)->with('status', 'Jadwal angsuran di-renegosiasi (versi baru).');
    }

    public function ingatkan(Request $request, string $idTermin): RedirectResponse
    {
        try {
            $this->service->tandaiDiingatkan((int) $idTermin, $request->user()->id_pengguna, $request->input('catatan'));
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Termin ditandai sudah diingatkan.');
    }

    public function feedback(Request $request, string $idTermin): RedirectResponse
    {
        try {
            $this->service->tandaiFeedback((int) $idTermin, $request->input('feedback') ?: null);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Feedback wali tersimpan.');
    }

    public function evaluasiPotongan(): RedirectResponse
    {
        $r = $this->service->evaluasiPotonganSemua();

        return back()->with('status', "Evaluasi potongan gelombang — diperiksa {$r['dievaluasi']}, terkunci {$r['earned']}, hangus {$r['hangus']}.");
    }

    /** Cetak rekap seluruh rencana aktif. */
    public function cetakRekap(): View
    {
        return view('angsuran-uang-pangkal.print-rekap', [
            'rows' => $this->service->list(),
            'company' => CompanySettings::find(1),
        ]);
    }

    /** Cetak dokumen rencana angsuran per santri. */
    public function cetakDetail(int $idSantri): View
    {
        try {
            $detail = $this->service->detail($idSantri);
        } catch (AppException $e) {
            return abort(404, $e->getMessage());
        }

        return view('angsuran-uang-pangkal.print-detail', ['d' => $detail, 'company' => CompanySettings::find(1)]);
    }

    /** @return array<string,mixed> */
    private function validasiTermin(Request $request): array
    {
        return $request->validate([
            'id_santri' => ['required', 'integer', 'exists:santri,id'],
            'disepakati_pada' => ['required', 'date'],
            'catatan' => ['nullable', 'string'],
            'termin' => ['required', 'array', 'min:1'],
            'termin.*.nominal' => ['required', 'numeric', 'gt:0'],
            'termin.*.jatuh_tempo' => ['required', 'date'],
            'termin.*.keterangan' => ['nullable', 'string'],
        ]);
    }
}
