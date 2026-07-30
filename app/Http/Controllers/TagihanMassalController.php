<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\Jenjang;
use App\Models\TahunAjaran;
use App\Services\Modules\TagihanMassalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Terbitkan Tagihan Massal — daftar ulang tahunan untuk santri aktif.
 * Ada di grup KEPENDIDIKAN: yang ditagih santri yang sudah bersekolah, bukan calon.
 *
 * Pratinjau memakai POST, bukan GET: hasilnya bukan halaman yang pantas
 * di-bookmark. Yang penting, tombol yang MENERBITKAN berbeda dari tombol yang
 * menyusun pratinjau — tak ada tagihan yang terbit karena halaman ter-refresh.
 */
class TagihanMassalController extends Controller
{
    public function __construct(private readonly TagihanMassalService $service) {}

    public function index(): View
    {
        return view('tagihan-massal.index', $this->opsi() + ['filter' => [], 'hasil' => null]);
    }

    public function pratinjau(Request $request): View|RedirectResponse
    {
        $filter = $request->validate([
            // Tahun ajaran TAGIHAN — dipakai mencari tarif & dicap ke tagihannya.
            'tahun_ajaran' => ['required', 'string', 'exists:tahun_ajaran,kode'],
            'kode_jenjang' => ['required', 'string', 'exists:jenjang,kode'],
            'tingkat' => ['nullable', 'integer', 'min:1'],
            // Angkatan (tahun MASUK) hanya menyaring santri.
            'angkatan' => ['nullable', 'string', 'exists:tahun_ajaran,kode'],
        ]);

        try {
            $hasil = $this->service->pratinjau($filter);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return view('tagihan-massal.index', $this->opsi() + ['filter' => $filter, 'hasil' => $hasil]);
    }

    public function terbitkan(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tahun_ajaran' => ['required', 'string', 'exists:tahun_ajaran,kode'],
            'jatuh_tempo' => ['nullable', 'date'],
            'nominal' => ['required', 'array'],
            'nominal.*' => ['nullable', 'numeric', 'gt:0'],
        ]);

        // Hanya baris yang dicentang yang ikut. Baris tak tercentang tidak dikirim
        // peramban sama sekali, jadi cukup disaring di sini.
        $dipilih = array_flip((array) $request->input('pilih', []));
        $kiriman = array_intersect_key($data['nominal'], $dipilih);

        try {
            $hasil = $this->service->terbitkan(
                $data['tahun_ajaran'],
                $kiriman,
                (int) $request->user()->id_pengguna,
                ['jatuh_tempo' => $data['jatuh_tempo'] ?? null],
            );
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('tagihan_massal.index')->with('status',
            "{$hasil['terbit']} tagihan daftar ulang terbit untuk {$hasil['santri']} santri (total Rp "
            .number_format((float) $hasil['total'], 0, ',', '.').').'
        );
    }

    private function opsi(): array
    {
        return [
            'opsiTa' => TahunAjaran::orderByDesc('kode')->pluck('kode', 'kode')->all(),
            'opsiJenjang' => Jenjang::orderBy('urutan')->orderBy('kode')->pluck('nama', 'kode')->all(),
        ];
    }
}
