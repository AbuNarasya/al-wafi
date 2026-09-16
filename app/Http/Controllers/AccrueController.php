<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Http\Requests\AccrueRequest;
use App\Models\Accrue;
use App\Models\Bagian;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Services\Modules\AccrueService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Accrue & Prepaid (jurnal penyesuaian). Controller tipis → AccrueService.
 * Reversal awal bulan (runReversal) membalik semua accrue aktif periode lalu.
 */
class AccrueController extends Controller
{
    public function __construct(private readonly AccrueService $service) {}

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $fStatus = trim((string) $request->query('status', ''));

        $rows = Accrue::query()
            ->when($q !== '', fn ($query) => $query->where(
                fn ($w) => $w->where('nomor_referensi', 'ilike', "%{$q}%")->orWhere('keterangan', 'ilike', "%{$q}%"),
            ))
            ->when($fStatus !== '', fn ($query) => $query->where('status', $fStatus))
            ->orderByDesc('tanggal')->orderByDesc('id_accrue')
            ->paginate(25)->withQueryString();

        return view('accrue.index', [
            'rows' => $rows,
            'q' => $q,
            'filter' => ['status' => $fStatus],
            'opsiStatus' => ['aktif' => 'Aktif', 'void' => 'Void'],
        ]);
    }

    public function create(): View
    {
        return view('accrue.create', $this->opsi());
    }

    public function store(AccrueRequest $request): RedirectResponse
    {
        $data = $request->validated();
        // Layar bertanya "posting jurnal sekarang?", service menerima
        // kebalikannya. Diterjemahkan di sini supaya pertanyaannya tetap positif
        // di mata petugas — isian bermakna negatif ("jangan jurnal") adalah cara
        // tercepat membuat orang salah centang.
        $saldoAwal = ! $request->boolean('posting_jurnal');
        $data['tanpa_jurnal'] = $saldoAwal;
        unset($data['posting_jurnal']);

        try {
            $this->service->create($data, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('accrue.index')->with('status', $saldoAwal
            ? 'Accrue saldo awal dicatat TANPA jurnal. Buku besarnya ikut lewat baris turunan di menu Saldo Awal.'
            : 'Accrue berhasil diposting.');
    }

    /** Reversal awal bulan — balik semua accrue aktif dari periode sebelumnya. */
    public function runReversal(Request $request): RedirectResponse
    {
        try {
            $hasil = $this->service->runReversal($request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('accrue.index')->with('status', "Reversal selesai — {$hasil['reversed']} accrue dibalik.");
    }

    private function opsi(): array
    {
        return [
            'coaOptions' => ['' => '— pilih —'] + CoaDetail::where('status', 'aktif')->orderBy('kode_coa')->get()
                ->mapWithKeys(fn ($c) => [$c->kode_coa => "{$c->kode_coa} — {$c->nama_coa}"])->all(),
            'unitOptions' => ['' => '— Default modul —'] + BusinessUnit::where('status', 'aktif')->orderBy('kode_unit')->get()
                ->mapWithKeys(fn ($u) => [$u->kode_unit => "{$u->kode_unit} — {$u->nama_unit}"])->all(),
            'bagianOptions' => ['' => '— (opsional) —'] + Bagian::where('status', 'aktif')->orderBy('kode_bagian')->get()
                ->mapWithKeys(fn ($b) => [$b->kode_bagian => "{$b->kode_bagian} — {$b->nama_bagian}"])->all(),
        ];
    }
}
