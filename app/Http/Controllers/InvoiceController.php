<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Http\Requests\InvoiceRequest;
use App\Models\Bagian;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\Invoice;
use App\Models\Vendor;
use App\Services\Modules\InvoiceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Invoice Vendor (pengakuan hutang usaha, accrual). Controller tipis →
 * InvoiceService. Jurnal: Debit rincian; Kredit hutang. Dibayar via Kas Keluar.
 */
class InvoiceController extends Controller
{
    public function __construct(private readonly InvoiceService $service) {}

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $fVendor = trim((string) $request->query('vendor', ''));
        $fStatus = trim((string) $request->query('status', ''));

        $rows = Invoice::query()->with('vendor')
            ->when($q !== '', fn ($query) => $query->where(
                fn ($w) => $w->where('nomor_invoice', 'ilike', "%{$q}%")->orWhere('nomor_ref_internal', 'ilike', "%{$q}%"),
            ))
            ->when($fVendor !== '', fn ($query) => $query->where('kode_vendor', $fVendor))
            ->when($fStatus !== '', fn ($query) => $query->where('status', $fStatus))
            ->orderByDesc('tanggal_invoice')->orderByDesc('id_invoice')
            ->paginate(25)->withQueryString();

        return view('invoices.index', [
            'rows' => $rows,
            'q' => $q,
            'filter' => ['vendor' => $fVendor, 'status' => $fStatus],
            'opsiVendor' => \App\Models\Vendor::orderBy('nama_vendor')->pluck('nama_vendor', 'kode_vendor')->all(),
            'opsiStatus' => ['belum_bayar' => 'Belum Bayar', 'sebagian' => 'Sebagian', 'lunas' => 'Lunas', 'void' => 'Void'],
        ]);
    }

    public function create(): View
    {
        return view('invoices.create', $this->opsi());
    }

    public function store(InvoiceRequest $request): RedirectResponse
    {
        try {
            $inv = $this->service->create([
                'id_po' => $request->input('id_po') ?: null,
                'nomor_invoice' => $request->input('nomor_invoice'),
                'tanggal_invoice' => $request->input('tanggal_invoice'),
                'tanggal_jatuh_tempo' => $request->input('tanggal_jatuh_tempo'),
                'kode_vendor' => $request->input('kode_vendor'),
                'kode_unit' => $request->input('kode_unit'),
                'kode_coa_hutang' => $request->input('kode_coa_hutang'),
                'keterangan' => $request->input('keterangan'),
                'details' => $request->details(),
            ], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('invoices.show', $inv->id_invoice)->with('status', "Invoice {$inv->nomor_invoice} berhasil diposting.");
    }

    public function show(Invoice $invoice): View
    {
        $invoice->load(['details', 'vendor', 'unit']);

        return view('invoices.show', ['inv' => $invoice]);
    }

    public function void(Request $request, Invoice $invoice): RedirectResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'max:255']]);

        try {
            $this->service->void($invoice->id_invoice, $data['alasan'], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('invoices.index')->with('status', "Invoice {$invoice->nomor_invoice} berhasil di-void.");
    }

    private function opsi(): array
    {
        // PO status open/sebagian → dropdown referensi + data untuk auto-isi baris.
        $openPos = \App\Models\PurchaseOrder::with('details')
            ->whereIn('status', ['open', 'sebagian'])
            ->orderByDesc('id_po')->get();

        return [
            // Termin pembayaran per vendor (hari) → auto-hitung jatuh tempo.
            // metode 'termin' pakai termin_hari; 'tunai' = 0 (jatuh tempo = tgl invoice).
            'vendorTermin' => Vendor::where('status', 'aktif')->get(['kode_vendor', 'metode_pembayaran', 'termin_hari'])
                ->mapWithKeys(fn ($v) => [$v->kode_vendor => $v->metode_pembayaran === 'termin' ? (int) $v->termin_hari : 0])->all(),
            'poOptions' => ['' => '— tanpa PO —'] + $openPos
                ->mapWithKeys(fn ($p) => [$p->id_po => "{$p->nomor_po} — {$p->kode_vendor}"])->all(),
            'poData' => $openPos->map(fn ($p) => [
                'id_po' => $p->id_po,
                'kode_vendor' => $p->kode_vendor,
                'kode_unit' => $p->kode_unit,
                'details' => $p->details->map(fn ($d) => [
                    'kode_coa' => $d->kode_coa,
                    'keterangan' => $d->keterangan,
                    'kode_bagian' => '',
                    'kuantiti' => (string) $d->kuantiti,
                    'harga_satuan' => (string) $d->harga_satuan,
                    'aset_pilih' => '',
                ])->all(),
            ])->values()->all(),
            'vendorOptions' => ['' => '— pilih vendor —'] + Vendor::where('status', 'aktif')->orderBy('kode_vendor')->get()
                ->mapWithKeys(fn ($v) => [$v->kode_vendor => "{$v->kode_vendor} — {$v->nama_vendor}"])->all(),
            'unitOptions' => ['' => '— pilih unit —'] + BusinessUnit::where('status', 'aktif')->orderBy('kode_unit')->get()
                ->mapWithKeys(fn ($u) => [$u->kode_unit => "{$u->kode_unit} — {$u->nama_unit}"])->all(),
            'hutangOptions' => ['' => '— pilih akun hutang —'] + CoaDetail::where('status', 'aktif')->orderBy('kode_coa')->get()
                ->mapWithKeys(fn ($c) => [$c->kode_coa => "{$c->kode_coa} — {$c->nama_coa}"])->all(),
            'coaOptions' => CoaDetail::where('status', 'aktif')->orderBy('kode_coa')->get()
                ->map(fn ($c) => ['v' => $c->kode_coa, 'l' => "{$c->kode_coa} — {$c->nama_coa}"])->values()->all(),
            'bagianOptions' => Bagian::where('status', 'aktif')->orderBy('kode_bagian')->get()
                ->map(fn ($b) => ['v' => $b->kode_bagian, 'l' => "{$b->kode_bagian} — {$b->nama_bagian}"])->values()->all(),
            // Perlakuan aset per baris: buat draft baru atau tambah nilai ke aset yang ada.
            'asetOptions' => array_merge(
                [['v' => '__new__', 'l' => '➕ Buat aset baru (draft)']],
                \App\Models\Asset::orderBy('kode_aset')->get()
                    ->map(fn ($a) => ['v' => $a->kode_aset, 'l' => "{$a->kode_aset} — {$a->nama_aset}"])->values()->all(),
            ),
        ];
    }
}
