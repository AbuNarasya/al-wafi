<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\CatatanPenagihan;
use App\Models\Kelas;
use App\Models\Santri;
use App\Services\Modules\PenagihanTunggakanService;
use App\Support\Referensi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Penagihan Tunggakan — admin sekolah menagih wali secara manual & mencatat prosesnya per tagihan
 * (keputusan user 3 Nov 2026). Controller tipis → PenagihanTunggakanService.
 */
class PenagihanTunggakanController extends Controller
{
    public function __construct(private readonly PenagihanTunggakanService $service) {}

    public function index(Request $request): View
    {
        $filter = [];
        foreach (['jenjang', 'kelas', 'umur', 'status', 'q'] as $k) {
            $filter[$k] = trim((string) $request->query($k, ''));
        }
        $daftar = $this->service->daftar($filter);

        return view('penagihan-tunggakan.index', [
            'daftar' => $daftar,
            'ringkasan' => $this->service->ringkasan($daftar),
            'filter' => $filter,
            'opsiJenjang' => Referensi::jenjang(),
            'opsiKelas' => Kelas::where('status', 'aktif')->orderBy('kode_jenjang')->orderBy('tingkat')->orderBy('nama')->pluck('nama', 'id')->all(),
        ]);
    }

    public function show(int $id): View
    {
        $santri = Santri::with(['wali', 'kelas', 'jenjang'])->findOrFail($id);

        return view('penagihan-tunggakan.show', ['santri' => $santri] + $this->service->rincian($santri));
    }

    public function catat(Request $request, int $id): RedirectResponse
    {
        $santri = Santri::findOrFail($id);
        $data = $request->validate([
            'tagihan' => ['required', 'array', 'min:1'],
            'tagihan.*' => ['integer'],
            'ditagih_pada' => ['required', 'date', 'before_or_equal:today'],
            'cara' => ['required', Rule::in(array_keys(CatatanPenagihan::CARA))],
            'tanggapan' => ['required', 'string', 'max:2000'],
            'janji_tanggal' => ['nullable', 'date'],
            'janji_nominal' => ['nullable', 'numeric', 'min:0'],
            'tindak_lanjut' => ['nullable', 'string', 'max:2000'],
        ], [
            'tagihan.required' => 'Centang minimal satu tagihan yang ditagihkan.',
            'tanggapan.required' => 'Tanggapan wali wajib diisi.',
            'ditagih_pada.before_or_equal' => 'Tanggal penagihan tidak boleh di masa depan.',
        ], [
            'ditagih_pada' => 'tanggal penagihan', 'cara' => 'cara menagih', 'tanggapan' => 'tanggapan wali',
            'janji_tanggal' => 'tanggal janji bayar', 'janji_nominal' => 'nominal janji bayar', 'tindak_lanjut' => 'langkah berikutnya',
        ]);

        try {
            $n = $this->service->catat($santri, $data, $request->user()?->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('penagihan_tunggakan.show', $santri->id)
            ->with('status', "Catatan penagihan tersimpan untuk {$n} tagihan.");
    }
}
