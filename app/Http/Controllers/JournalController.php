<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Http\Requests\JournalRequest;
use App\Models\Bagian;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\JournalEntry;
use App\Services\Modules\JournalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Jurnal Umum (manual). Controller tipis: memanggil JournalService (create/void)
 * dan membungkus AppException menjadi flash error. Template acuan modul transaksi.
 */
class JournalController extends Controller
{
    public function __construct(private readonly JournalService $service) {}

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $from = $request->query('from');
        $to = $request->query('to');
        $fSumber = trim((string) $request->query('sumber', ''));
        $fStatus = trim((string) $request->query('status', ''));

        // Jurnal Umum = General Journal: tampilkan SEMUA entri jurnal (dari modul
        // mana pun), bukan hanya yang dibuat manual. Void tetap khusus JurnalUmum.
        $entries = JournalEntry::query()
            ->with('lines')
            ->when($from, fn ($query) => $query->whereDate('tanggal', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('tanggal', '<=', $to))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('referensi', 'ilike', "%{$q}%")
                ->orWhere('keterangan', 'ilike', "%{$q}%")
                ->orWhere('sumber_modul', 'ilike', "%{$q}%")
                ->orWhereHas('lines', fn ($lq) => $lq->where('kode_coa', 'ilike', "%{$q}%")
                    ->orWhere('nama_coa', 'ilike', "%{$q}%")->orWhere('keterangan', 'ilike', "%{$q}%")),
            ))
            ->when($fSumber !== '', fn ($query) => $query->where('sumber_modul', $fSumber))
            ->when($fStatus !== '', fn ($query) => $query->where('status', $fStatus))
            ->orderByDesc('tanggal')->orderByDesc('id')
            ->paginate(25)->withQueryString();

        return view('journal.index', [
            'entries' => $entries,
            'q' => $q,
            'from' => $from,
            'to' => $to,
            'filter' => ['sumber' => $fSumber, 'status' => $fStatus],
            'opsiSumber' => JournalEntry::query()->distinct()->orderBy('sumber_modul')
                ->pluck('sumber_modul', 'sumber_modul')->all(),
            'opsiStatus' => ['aktif' => 'Aktif', 'void' => 'Void'],
        ]);
    }

    public function create(): View
    {
        return view('journal.create', $this->opsi());
    }

    public function store(JournalRequest $request): RedirectResponse
    {
        try {
            $entry = $this->service->create([
                'tanggal' => $request->input('tanggal'),
                'kode_unit' => $request->input('kode_unit') ?: null,
                'keterangan' => $request->input('keterangan'),
                'id_pengguna' => $request->user()->id_pengguna,
                'lines' => $request->lines(),
            ]);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('journal.show', $entry->id)->with('status', "Jurnal {$entry->referensi} berhasil diposting.");
    }

    public function show(JournalEntry $journal): View
    {
        // Semua entri jurnal boleh dilihat (general journal); hanya JurnalUmum
        // yang bisa di-void dari sini (yang lain via modul asalnya).
        $journal->load(['lines', 'user']);

        return view('journal.show', ['entry' => $journal]);
    }

    public function void(Request $request, JournalEntry $journal): RedirectResponse
    {
        try {
            $this->service->void($journal->id, [
                'tanggal' => now()->toDateString(),
                'id_pengguna' => $request->user()->id_pengguna,
            ]);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('journal.index')->with('status', "Jurnal {$journal->referensi} berhasil di-void.");
    }

    /** @return array{coaOptions:array,bagianOptions:array,unitOptions:array} */
    private function opsi(): array
    {
        return [
            'coaOptions' => CoaDetail::where('status', 'aktif')->orderBy('kode_coa')->get()
                ->map(fn ($c) => ['v' => $c->kode_coa, 'l' => "{$c->kode_coa} — {$c->nama_coa}"])->values()->all(),
            'bagianOptions' => Bagian::where('status', 'aktif')->orderBy('kode_bagian')->get()
                ->map(fn ($b) => ['v' => $b->kode_bagian, 'l' => "{$b->kode_bagian} — {$b->nama_bagian}"])->values()->all(),
            'unitOptions' => BusinessUnit::where('status', 'aktif')->orderBy('kode_unit')->get()
                ->mapWithKeys(fn ($u) => [$u->kode_unit => "{$u->kode_unit} — {$u->nama_unit}"])->all(),
            'inventoryOptions' => \App\Models\Inventory::orderBy('nama_persediaan')->get()
                ->map(fn ($it) => [
                    'v' => $it->kode_persediaan,
                    'l' => "{$it->nama_persediaan} (stok ".rtrim(rtrim((string) ($it->stok_masuk - $it->stok_keluar), '0'), '.').')',
                ])->values()->all(),
        ];
    }
}
