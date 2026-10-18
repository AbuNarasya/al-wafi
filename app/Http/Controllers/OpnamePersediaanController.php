<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\Bagian;
use App\Models\KategoriBarang;
use App\Models\LokasiPersediaan;
use App\Models\OpnamePersediaan;
use App\Services\Modules\OpnamePersediaanService;
use App\Support\Akses;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Stok opname berdokumen. Hak: opname-persediaan buat = membuat, menghitung, mengajukan;
 * hapus = membatalkan; menyetujui/menolak = persetujuan-persediaan ubah.
 * Logika: [[OpnamePersediaanService]].
 */
class OpnamePersediaanController extends Controller
{
    public function __construct(private readonly OpnamePersediaanService $service) {}

    public function index(Request $request): View
    {
        $status = (string) $request->query('status', '');

        return view('opname-persediaan.index', [
            'rows' => OpnamePersediaan::with(['lokasi', 'kategori'])->withCount([
                'details', 'details as dihitung_count' => fn ($q) => $q->whereNotNull('stok_fisik'),
            ])
                ->when($status !== '', fn ($q) => $q->where('status', $status))
                ->orderByDesc('tanggal')->orderByDesc('id_opname')->paginate(25)->withQueryString(),
            'status' => $status,
        ]);
    }

    public function create(): View
    {
        return view('opname-persediaan.create', [
            'lokasiOptions' => LokasiPersediaan::pilihan(),
            'kategoriOptions' => ['' => '— semua kategori —'] + KategoriBarang::where('status', 'aktif')->orderBy('nama')->pluck('nama', 'kode')->all(),
            'bagianOptions' => ['' => '— pilih nanti —'] + Bagian::orderBy('nama_bagian')->pluck('nama_bagian', 'kode_bagian')->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tanggal' => ['required', 'date'],
            'kode_lokasi' => ['required', 'string', Rule::exists('lokasi_persediaan', 'kode')->where('status', 'aktif')],
            'kode_kategori' => ['nullable', 'string', 'exists:kategori_barang,kode'],
            'kode_bagian' => ['nullable', 'string', 'exists:bagian,kode_bagian'],
            'cakupan' => ['required', Rule::in(['pernah', 'semua'])],
            'keterangan' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $doc = $this->service->buat($data, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('opname_persediaan.show', $doc->id_opname)->with('status', "Lembar opname {$doc->nomor} siap diisi.");
    }

    public function show(OpnamePersediaan $opname): View
    {
        $opname->load(['details.persediaan', 'lokasi', 'kategori', 'bagian', 'pembuat', 'pengaju', 'penyetuju']);

        return view('opname-persediaan.show', [
            'doc' => $opname,
            'baris' => $opname->details->sortBy(fn ($d) => $d->persediaan?->nama_persediaan)->values(),
            // Hitung buta: selama draft, petugas penghitung tak melihat stok tercatat —
            // angka yang terlihat menggoda untuk "dicocokkan" alih-alih dihitung.
            'tampilkanTercatat' => $opname->status !== 'draft' || Akses::boleh('persetujuan-persediaan', 'ubah'),
            'bagianOptions' => ['' => '— pilih bagian —'] + Bagian::orderBy('nama_bagian')->pluck('nama_bagian', 'kode_bagian')->all(),
        ]);
    }

    /**
     * Simpan hitungan. Dikirim sebagai SATU isian JSON (`hitungan`): toko dengan ribuan
     * barang akan melampaui batas `max_input_vars` PHP (1000) bila tiap barang satu isian,
     * dan sisanya terbuang diam-diam.
     */
    public function hitung(Request $request, OpnamePersediaan $opname): RedirectResponse
    {
        $data = $request->validate([
            'hitungan' => ['required', 'json'],
            'kode_bagian' => ['nullable', 'string', 'exists:bagian,kode_bagian'],
        ]);

        try {
            $this->service->simpanHitungan($opname->id_opname, (array) json_decode($data['hitungan'], true),
                $request->has('kode_bagian') ? (string) $request->input('kode_bagian') : null, $request->user()->id_pengguna);
            if ($request->boolean('ajukan')) {
                $this->service->ajukan($opname->id_opname, $request->user()->id_pengguna);

                return back()->with('status', 'Hitungan disimpan dan diajukan untuk disetujui.');
            }
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Hitungan disimpan.');
    }

    public function putus(Request $request, OpnamePersediaan $opname): RedirectResponse
    {
        $data = $request->validate([
            'putusan' => ['required', Rule::in(['setujui', 'tolak'])],
            'catatan' => ['nullable', 'required_if:putusan,tolak', 'string', 'max:255'],
        ], ['catatan.required_if' => 'Tulis alasan penolakan supaya penghitung tahu apa yang diperbaiki.']);

        try {
            $data['putusan'] === 'setujui'
                ? $this->service->setujui($opname->id_opname, $request->user()->id_pengguna)
                : $this->service->tolak($opname->id_opname, $data['catatan'], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', $data['putusan'] === 'setujui'
            ? 'Opname disetujui — stok & jurnal selisih sudah dibukukan.'
            : 'Opname dikembalikan ke penghitung.');
    }

    public function batal(Request $request, OpnamePersediaan $opname): RedirectResponse
    {
        try {
            $this->service->batal($opname->id_opname, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('opname_persediaan.index')->with('status', "Opname {$opname->nomor} dibatalkan.");
    }
}
