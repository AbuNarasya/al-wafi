<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Http\Requests\CashInRequest;
use App\Models\Bagian;
use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CashIn;
use App\Models\CoaDetail;
use App\Models\Customer;
use App\Services\Modules\CashInService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Kas Masuk (Receivable Voucher). Controller tipis → CashInService.
 * Jurnal: Debit Kas/Bank; Kredit tiap akun rincian.
 */
class CashInController extends Controller
{
    public function __construct(private readonly CashInService $service) {}

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $fCustomer = trim((string) $request->query('customer', ''));
        $fStatus = trim((string) $request->query('status', ''));

        $rows = CashIn::query()->with(['customer', 'unit'])
            ->when($q !== '', fn ($query) => $query->where(
                fn ($w) => $w->where('nomor_transaksi', 'ilike', "%{$q}%")->orWhere('keterangan', 'ilike', "%{$q}%"),
            ))
            ->when($fCustomer !== '', fn ($query) => $query->where('kode_customer', $fCustomer))
            ->when($fStatus !== '', fn ($query) => $query->where('status', $fStatus))
            ->orderByDesc('tanggal')->orderByDesc('kode_transaksi')
            ->paginate(25)->withQueryString();

        return view('cash-in.index', [
            'rows' => $rows,
            'q' => $q,
            'filter' => ['customer' => $fCustomer, 'status' => $fStatus],
            'opsiCustomer' => \App\Models\Customer::orderBy('nama_customer')->pluck('nama_customer', 'kode_customer')->all(),
            'opsiStatus' => ['aktif' => 'Aktif', 'void' => 'Void'],
        ]);
    }

    public function create(): View
    {
        return view('cash-in.create', $this->opsi());
    }

    public function store(CashInRequest $request): RedirectResponse
    {
        try {
            $rec = $this->service->create([
                'tanggal' => $request->input('tanggal'),
                'kode_unit' => $request->input('kode_unit'),
                'kode_rekening' => $request->input('kode_rekening'),
                'kode_customer' => $request->input('kode_customer') ?: null,
                'referensi' => $request->input('referensi'),
                'keterangan' => $request->input('keterangan'),
                'details' => $request->details(),
            ], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('cash_in.show', $rec->kode_transaksi)->with('status', "Kas Masuk {$rec->nomor_transaksi} berhasil diposting.");
    }

    public function show(CashIn $cash_in): View
    {
        $cash_in->load(['details', 'customer', 'unit', 'rekening', 'user']);

        return view('cash-in.show', ['rec' => $cash_in]);
    }

    public function void(Request $request, CashIn $cash_in): RedirectResponse
    {
        $request->validate(['alasan' => ['required', 'string', 'max:255']]);

        try {
            $this->service->void(
                $cash_in->kode_transaksi,
                ['tanggal' => now()->toDateString(), 'alasan' => $request->input('alasan')],
                $request->user()->id_pengguna,
                $request->user()->nama,
            );
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('cash_in.index')->with('status', "Kas Masuk {$cash_in->nomor_transaksi} berhasil di-void.");
    }

    /** Akui pendapatan dari uang muka customer (parsial/penuh) — dipanggil dari Kontrol → Uang Muka Customer. */
    public function akui(Request $request, CashIn $cash_in): RedirectResponse
    {
        $data = $request->validate([
            'detail_id' => ['required', 'integer'],
            'kode_coa_pendapatan' => ['required', 'string', 'exists:coa_detail,kode_coa'],
            'nominal' => ['nullable', 'numeric', 'gt:0'],
        ]);

        try {
            $this->service->akuiPendapatan($cash_in->kode_transaksi, $data, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Pengakuan pendapatan berhasil diposting.');
    }

    private function opsi(): array
    {
        // Preview nomor RV (KM-YYMM-NNNN) berikutnya — indikatif; nomor final
        // ditetapkan saat posting.
        $base = \App\Services\Ledger\DocNumber::docBase('KM', now());
        $last = \App\Models\CashIn::where('nomor_transaksi', 'like', $base.'%')
            ->orderByDesc('nomor_transaksi')->value('nomor_transaksi');

        return [
            'nomorPreview' => \App\Services\Ledger\DocNumber::nextDocNumber($base, $last),
            'unitOptions' => BusinessUnit::where('status', 'aktif')->orderBy('kode_unit')->get()
                ->mapWithKeys(fn ($u) => [$u->kode_unit => "{$u->kode_unit} — {$u->nama_unit}"])->all(),
            'rekeningOptions' => BankAccount::where('status', 'aktif')->with('coa')->orderBy('kode_coa')->get()
                ->mapWithKeys(fn ($r) => [$r->kode_coa => "{$r->nama_rekening} ({$r->kode_coa})"])->all(),
            'customerOptions' => ['' => '— Tanpa customer —'] + Customer::where('status', 'aktif')->orderBy('kode_customer')->get()
                ->mapWithKeys(fn ($c) => [$c->kode_customer => "{$c->kode_customer} — {$c->nama_customer}"])->all(),
            'coaOptions' => CoaDetail::where('status', 'aktif')->orderBy('kode_coa')->get()
                ->map(fn ($c) => ['v' => $c->kode_coa, 'l' => "{$c->kode_coa} — {$c->nama_coa}"])->values()->all(),
            'bagianOptions' => Bagian::where('status', 'aktif')->orderBy('kode_bagian')->get()
                ->map(fn ($b) => ['v' => $b->kode_bagian, 'l' => "{$b->kode_bagian} — {$b->nama_bagian}"])->values()->all(),
            'inventoryOptions' => \App\Models\Inventory::orderBy('nama_persediaan')->get()
                ->map(fn ($it) => [
                    'v' => $it->kode_persediaan,
                    'l' => "{$it->nama_persediaan} (stok ".rtrim(rtrim((string) ($it->stok_masuk - $it->stok_keluar), '0'), '.').')',
                ])->values()->all(),
        ];
    }
}
