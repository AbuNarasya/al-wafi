<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\KebijakanKhusus;
use App\Models\Santri;
use App\Models\TahunAjaran;
use App\Services\Modules\KebijakanKhususService;
use App\Support\SumberLampiran;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Kebijakan Khusus santri: keringanan, potongan, beasiswa, dsb.
 *
 * Menggantikan "nominal khusus SPP" yang dulu ditimpa begitu saja. Dua surat
 * wajib terlampir sebelum boleh disetujui — syaratnya ditegakkan service,
 * bukan hanya diingatkan di layar.
 */
class KebijakanKhususController extends Controller
{
    /** Perilaku biaya yang bisa dikenai kebijakan — kosakata yang sama dengan jenis biaya. */
    private const PERILAKU = [
        'spp' => 'SPP',
        'uang_pangkal' => 'Uang Pangkal',
        'daftar_ulang' => 'Daftar Ulang',
        'perlengkapan' => 'Perlengkapan',
        'registrasi' => 'Registrasi',
        'lain' => 'Lain-lain',
    ];

    public function __construct(private KebijakanKhususService $service = new KebijakanKhususService) {}

    public function index(Request $request): View|RedirectResponse
    {
        $urut = array_key_exists((string) $request->query('urut'), KebijakanKhususService::URUTAN) ? (string) $request->query('urut') : 'terbaru';
        $cari = trim((string) $request->query('q', ''));
        $rows = $this->service->daftar(
            trim((string) $request->query('status', '')) ?: null,
            (int) $request->query('santri') ?: null,
            $cari !== '' ? $cari : null,
            $urut,
        );
        // Baris terakhir sebuah halaman baru saja diputus → halaman itu kosong; pindah ke halaman terakhir yang berisi.
        if ($rows->isEmpty() && $rows->currentPage() > 1) {
            return redirect()->route('kebijakan_khusus.index', ['page' => max(1, $rows->lastPage())] + $request->except('page'));
        }

        return view('kebijakan-khusus.index', [
            'rows' => $rows,
            'status' => trim((string) $request->query('status', '')),
            'cari' => $cari,
            'urut' => $urut,
            'urutOptions' => KebijakanKhususService::URUTAN,
            'perilakuOptions' => self::PERILAKU,
            'jenisOptions' => KebijakanKhusus::JENIS,
            'caraOptions' => KebijakanKhusus::CARA,
            'taOptions' => ['' => '— semua tahun ajaran —'] + TahunAjaran::orderByDesc('kode')->pluck('kode', 'kode')->all(),
            // TANPA batas jumlah: dulu limit(500) menyembunyikan santri setelah urutan ke-500 menurut abjad
            // (produksi 764 santri aktif, laporan user 3 Nov 2026). Kotak pilihnya bisa dicari.
            'santriOptions' => Santri::whereIn('status', ['aktif', 'calon'])->orderBy('nama')
                ->get(['id', 'nama', 'nis'])->mapWithKeys(fn ($s) => [$s->id => trim("{$s->nama} ({$s->nis})", ' ()')])->all(),
            // Surat yang masih kurang per baris — dihitung sekali per halaman.
            'kurangSurat' => collect($rows->items())->mapWithKeys(
                fn ($k) => [$k->id => $this->service->suratYangKurang($k->id)],
            )->all(),
            'jenisPermohonan' => SumberLampiran::KEBIJAKAN_PERMOHONAN,
            'jenisPersetujuan' => SumberLampiran::KEBIJAKAN_PERSETUJUAN,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'id_santri' => ['required', 'integer', 'exists:santri,id'],
            'jenis' => ['required', Rule::in(array_keys(KebijakanKhusus::JENIS))],
            'perilaku' => ['required', Rule::in(array_keys(self::PERILAKU))],
            'cara' => ['required', Rule::in(array_keys(KebijakanKhusus::CARA))],
            'besaran' => ['required', 'numeric', 'min:0'],
            'tahun_ajaran' => ['nullable', 'string', 'exists:tahun_ajaran,kode'],
            'berlaku_mulai' => ['nullable', 'date'],
            'berlaku_sampai' => ['nullable', 'date', 'after_or_equal:berlaku_mulai'],
            'alasan' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $k = $this->service->ajukan($d, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('kebijakan_khusus.index')->with(
            'status',
            "Kebijakan diajukan. Lampirkan surat permohonan wali & surat persetujuan yayasan pada baris #{$k->id} sebelum bisa disetujui.",
        );
    }

    public function setujui(Request $request, int $id): RedirectResponse
    {
        $d = $request->validate(['catatan_keputusan' => ['nullable', 'string', 'max:1000']]);

        try {
            $this->service->setujui($id, $request->user()->id_pengguna, $d['catatan_keputusan'] ?? null);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return $this->kembaliKeDaftar($request)->with('status', 'Kebijakan disetujui dan mulai berlaku pada penagihan berikutnya.');
    }

    public function tolak(Request $request, int $id): RedirectResponse
    {
        $d = $request->validate(['catatan_keputusan' => ['required', 'string', 'max:1000']]);

        try {
            $this->service->tolak($id, $request->user()->id_pengguna, $d['catatan_keputusan']);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return $this->kembaliKeDaftar($request)->with('status', 'Kebijakan ditolak.');
    }

    public function akhiri(Request $request, int $id): RedirectResponse
    {
        $d = $request->validate(['catatan_keputusan' => ['required', 'string', 'max:1000']]);

        try {
            $this->service->akhiri($id, $request->user()->id_pengguna, $d['catatan_keputusan']);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return $this->kembaliKeDaftar($request)->with('status', 'Kebijakan diakhiri; penagihan berikutnya kembali ke tarif normal.');
    }

    /**
     * Kembali ke daftar dengan saringan, kata cari, urutan & halaman yang sama (9 Nov 2026) — persetujuan
     * satu per satu tak lagi memaksa petugas menyaring ulang setiap kali. Formulir keputusan mengirimnya
     * sebagai isian tersembunyi `daftar[...]`; hanya kunci yang dikenal yang diteruskan.
     */
    private function kembaliKeDaftar(Request $request): RedirectResponse
    {
        $d = array_filter(array_intersect_key((array) $request->input('daftar', []), array_flip(['status', 'q', 'urut', 'page'])),
            fn ($v) => is_scalar($v) && (string) $v !== '');

        return redirect()->route('kebijakan_khusus.index', $d);
    }
}
