<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Services\Modules\OutstandingSppService;
use App\Services\Modules\TahunAjaranService;
use App\Support\Referensi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Daftar Outstanding SPP — tagihan SPP yang sudah terbit tetapi belum tertutup,
 * beserta koreksi nominal yang salah ketik. Controller tipis → OutstandingSppService.
 */
class OutstandingSppController extends Controller
{
    public function __construct(private readonly OutstandingSppService $service) {}

    public function index(Request $request): View
    {
        $filter = [
            'tahun_ajaran' => trim((string) $request->query('tahun_ajaran', '')),
            'periode' => trim((string) $request->query('periode', '')),
            'jenjang' => trim((string) $request->query('jenjang', '')),
            'q' => trim((string) $request->query('q', '')),
        ];
        $daftar = $this->service->daftar($filter);

        return view('outstanding-spp.index', [
            'daftar' => $daftar,
            'ringkasan' => $this->service->ringkasan($daftar),
            'filter' => $filter,
            'opsiTahunAjaran' => $this->service->opsiTahunAjaran(),
            'opsiPeriode' => $this->service->opsiPeriode(),
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

        $pesan = "Nominal SPP {$t->periode} untuk {$t->santri?->nama} dikoreksi menjadi Rp "
            .number_format((float) $t->nominal, 0, ',', '.').'.';
        if ($t->status === 'lunas') {
            $pesan .= ' Tagihannya kini lunas dan keluar dari daftar outstanding.';
        }

        return back()->with('status', $pesan);
    }
}
