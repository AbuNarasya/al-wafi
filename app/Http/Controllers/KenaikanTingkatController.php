<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\Jenjang;
use App\Models\TahunAjaran;
use App\Services\Modules\KenaikanTingkatService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Kenaikan Tingkat & Kelulusan massal — dalam satu jenjang, serentak satu angkatan.
 *
 * Pratinjau dulu, eksekusi kemudian, dengan tombol yang berbeda: memuat ulang
 * halaman tak boleh menaikkan siapa pun.
 */
class KenaikanTingkatController extends Controller
{
    public function __construct(private readonly KenaikanTingkatService $service) {}

    public function index(): View
    {
        return view('kenaikan-tingkat.index', $this->opsi() + ['filter' => [], 'hasil' => null]);
    }

    public function pratinjau(Request $request): View|RedirectResponse
    {
        $filter = $request->validate([
            // Tahun ajaran TUJUAN — tahun yang akan dijalani sesudah naik.
            'tahun_ajaran' => ['required', 'string', 'exists:tahun_ajaran,kode'],
            'kode_jenjang' => ['required', 'string', 'exists:jenjang,kode'],
            'tingkat' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $hasil = $this->service->pratinjau($filter);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return view('kenaikan-tingkat.index', $this->opsi() + ['filter' => $filter, 'hasil' => $hasil]);
    }

    public function eksekusi(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tahun_ajaran' => ['required', 'string', 'exists:tahun_ajaran,kode'],
            'tanggal_lulus' => ['nullable', 'date'],
            'keputusan' => ['required', 'array'],
            'keputusan.*' => ['string', 'in:'.implode(',', array_keys(KenaikanTingkatService::KEPUTUSAN))],
        ]);

        try {
            $hasil = $this->service->eksekusi(
                $data['tahun_ajaran'],
                $data['keputusan'],
                (int) $request->user()->id_pengguna,
                ['tanggal_lulus' => $data['tanggal_lulus'] ?? null],
            );
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('kenaikan_tingkat.index')->with('status',
            "T.A {$data['tahun_ajaran']}: {$hasil['naik']} santri naik tingkat, "
            ."{$hasil['mengulang']} mengulang, {$hasil['lulus']} lulus."
        );
    }

    private function opsi(): array
    {
        return [
            'opsiTa' => TahunAjaran::orderByDesc('kode')->pluck('kode', 'kode')->all(),
            'opsiJenjang' => Jenjang::orderBy('urutan')->orderBy('kode')->pluck('nama', 'kode')->all(),
            'opsiKeputusan' => KenaikanTingkatService::KEPUTUSAN,
        ];
    }
}
