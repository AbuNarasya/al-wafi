<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Services\Modules\NisService;
use App\Support\Referensi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * NIS — pengaturan formatnya + penerbitan massal dengan pratinjau.
 * Controller tipis → NisService.
 */
class NisController extends Controller
{
    public function __construct(private readonly NisService $service) {}

    public function index(Request $request): View
    {
        $filter = [
            'jenjang' => trim((string) $request->query('jenjang', '')),
            'tahun_ajaran' => trim((string) $request->query('tahun_ajaran', '')),
        ];
        $daftar = $this->service->pratinjau($filter);

        return view('nis.index', [
            'daftar' => $daftar,
            'filter' => $filter,
            'pengaturan' => $this->service->pengaturan(),
            'contoh' => $this->service->contoh(),
            'opsiJenjang' => Referensi::jenjang(),
            // Hanya T.A yang benar-benar muncul di daftar — dropdown tanpa pilihan kosong.
            'opsiTahunAjaran' => collect($daftar)->pluck('tahun_ajaran')->filter()->unique()->sortDesc()->values()->all(),
        ]);
    }

    public function simpanFormat(Request $request): RedirectResponse
    {
        $data = $request->validate(['format' => ['required', 'string', 'max:100']]);

        try {
            $this->service->simpanFormat($data['format']);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Format NIS tersimpan. Contoh: '.$this->service->contoh());
    }

    public function terbitkan(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'id_santri' => ['required', 'array', 'min:1'],
            'id_santri.*' => ['integer', 'exists:santri,id'],
        ]);

        try {
            $hasil = $this->service->terbitkan($data['id_santri'], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        $pesan = "{$hasil['terbit']} NIS diterbitkan.";
        if ($hasil['dilewati'] > 0) {
            $pesan .= " {$hasil['dilewati']} dilewati karena sudah punya NIS untuk jenjangnya.";
        }

        return back()->with('status', $pesan);
    }
}
