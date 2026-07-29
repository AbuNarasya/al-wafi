<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\JenisBiaya;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Services\Modules\TagihanLainService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Tagihan Lain-lain (seragam, kegiatan, denda). Terbitkan ke banyak santri
 * sekaligus; akrual bila jenis biaya punya akun piutang. Controller tipis →
 * TagihanLainService.
 */
class TagihanLainController extends Controller
{
    public function __construct(private readonly TagihanLainService $service) {}

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $fJenis = trim((string) $request->query('jenis', ''));
        $fStatus = trim((string) $request->query('status', ''));

        $jenisLain = JenisBiaya::whereIn('tipe', \App\Models\TipeBiaya::kodeBerperilaku('lain'))->orderBy('nama')->pluck('nama', 'kode');
        $rows = TagihanSantri::query()->with(['santri', 'jenis'])
            ->whereIn('kode_jenis', $jenisLain->keys())
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('keterangan', 'ilike', "%{$q}%")
                ->orWhereHas('santri', fn ($s) => $s->where('nama', 'ilike', "%{$q}%")
                    ->orWhere('no_pendaftaran', 'ilike', "%{$q}%")->orWhere('nis', 'ilike', "%{$q}%")),
            ))
            ->when($fJenis !== '', fn ($query) => $query->where('kode_jenis', $fJenis))
            ->when($fStatus !== '', fn ($query) => $query->where('status', $fStatus))
            ->orderByDesc('id')->paginate(30)->withQueryString();

        return view('tagihan-lain.index', [
            'rows' => $rows,
            'q' => $q,
            'filter' => ['jenis' => $fJenis, 'status' => $fStatus],
            'opsiJenis' => $jenisLain->all(),
            'opsiStatus' => ['belum_bayar' => 'Belum Bayar', 'sebagian' => 'Sebagian', 'lunas' => 'Lunas', 'batal' => 'Batal'],
        ]);
    }

    public function create(): View
    {
        return view('tagihan-lain.create', [
            'jenisOptions' => ['' => '— pilih jenis —'] + JenisBiaya::whereIn('tipe', \App\Models\TipeBiaya::kodeBerperilaku('lain'))->where('status', 'aktif')->orderBy('kode')->get()
                // Jenjang ikut ditampilkan: sejak jenjang bisa diisi pada jenis
                // berperilaku "lain", dua baris bisa bernama mirip dan hanya
                // dibedakan jenjangnya.
                ->mapWithKeys(fn ($j) => [$j->kode => "{$j->kode} — {$j->nama}".($j->kode_jenjang ? " ({$j->kode_jenjang})" : '')])->all(),
            'santriAktif' => Santri::where('status', 'aktif')->orderBy('nama')->get(['id', 'nama', 'kode_jenjang']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kode_jenis' => ['required', 'string', 'exists:jenis_biaya,kode'],
            'id_santri' => ['required', 'array', 'min:1'],
            'id_santri.*' => ['integer', 'exists:santri,id'],
            'nominal' => ['required', 'numeric', 'gt:0'],
            'periode' => ['nullable', 'string', 'max:255'],
            'tanggal' => ['required', 'date'],
        ]);
        $data['id_santri'] = array_map('intval', $data['id_santri']);

        try {
            $this->service->terbitkan($data, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('tagihan_lain.index')->with('status', 'Tagihan lain berhasil diterbitkan.');
    }

    public function batalkan(string $id): RedirectResponse
    {
        try {
            $this->service->batalkan((int) $id);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('tagihan_lain.index')->with('status', 'Tagihan dibatalkan.');
    }
}
