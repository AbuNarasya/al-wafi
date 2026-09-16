<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Services\Modules\OutstandingLainService;
use App\Services\Modules\TahunAjaranService;
use App\Support\Money;
use App\Support\Referensi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Daftar Outstanding Tagihan Lain — tunggakan Kesantrian selain SPP.
 *
 * Controller tipis → OutstandingLainService. Modulnya sendiri (`outstanding-lain`),
 * mengikuti pola Outstanding SPP: memeriksa tunggakan itu pekerjaan harian,
 * menerbitkan tagihan tidak, dan keduanya tak selalu di tangan orang yang sama.
 */
class OutstandingLainController extends Controller
{
    public function __construct(private readonly OutstandingLainService $service) {}

    public function index(Request $request): View
    {
        $filter = [
            'tahun_ajaran' => trim((string) $request->query('tahun_ajaran', '')),
            'jenis' => trim((string) $request->query('jenis', '')),
            'jenjang' => trim((string) $request->query('jenjang', '')),
            'tempo' => trim((string) $request->query('tempo', '')),
            'q' => trim((string) $request->query('q', '')),
        ];
        $daftar = $this->service->daftar($filter);

        return view('outstanding-lain.index', [
            'daftar' => $daftar,
            'ringkasan' => $this->service->ringkasan($daftar),
            'filter' => $filter,
            'opsiJenis' => $this->service->opsiJenis(),
            'opsiTahunAjaran' => $this->service->opsiTahunAjaran(),
            'opsiJenjang' => Referensi::jenjang(),
            // Dipakai menandai baris yang tunggakannya dari tahun ajaran lama.
            'taBerjalan' => (new TahunAjaranService)->berjalan()?->kode,
        ]);
    }

    public function koreksi(Request $request, int $idTagihan): RedirectResponse
    {
        $data = $request->validate([
            // Nol SAH — itulah cara membebaskan santri yang telanjur tertagih.
            'nominal' => ['required', 'numeric', 'min:0'],
            'jatuh_tempo' => ['nullable', 'date'],
            'alasan' => ['required', 'string', 'max:255'],
        ]);

        try {
            $t = $this->service->koreksi($idTagihan, $data, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        $pesan = 'Tagihan '.($t->jenis?->nama ?? $t->kode_jenis).' untuk '.($t->santri?->nama ?? 'santri')
            .' kini '.Money::of($t->nominal).'.';
        if (in_array($t->status, ['lunas', 'dihapus'], true)) {
            $pesan .= ' Tagihannya keluar dari daftar outstanding.';
        }

        return back()->with('status', $pesan);
    }
}
