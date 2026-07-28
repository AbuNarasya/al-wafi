<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Http\Requests\PurchaseOrderRequest;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\PurchaseOrder;
use App\Models\Vendor;
use App\Services\Modules\PurchaseOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Purchase Order (dokumen komitmen, TANPA jurnal). Controller tipis →
 * PurchaseOrderService. Menjadi hutang saat di-invoice; bisa dibatalkan.
 */
class PurchaseOrderController extends Controller
{
    public function __construct(private readonly PurchaseOrderService $service) {}

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $fVendor = trim((string) $request->query('vendor', ''));
        $fStatus = trim((string) $request->query('status', ''));

        $rows = PurchaseOrder::query()->with('vendor')
            ->when($q !== '', fn ($query) => $query->where(
                fn ($w) => $w->where('nomor_po', 'ilike', "%{$q}%")->orWhere('keterangan', 'ilike', "%{$q}%"),
            ))
            ->when($fVendor !== '', fn ($query) => $query->where('kode_vendor', $fVendor))
            ->when($fStatus !== '', fn ($query) => $query->where('status', $fStatus))
            ->orderByDesc('tanggal_po')->orderByDesc('id_po')
            ->paginate(25)->withQueryString();

        return view('purchase-orders.index', [
            'rows' => $rows,
            'q' => $q,
            'filter' => ['vendor' => $fVendor, 'status' => $fStatus],
            'opsiVendor' => \App\Models\Vendor::orderBy('nama_vendor')->pluck('nama_vendor', 'kode_vendor')->all(),
            'opsiStatus' => ['open' => 'Open', 'sebagian' => 'Sebagian', 'selesai' => 'Selesai', 'batal' => 'Batal'],
        ]);
    }

    public function create(): View
    {
        return view('purchase-orders.create', $this->opsi());
    }

    public function store(PurchaseOrderRequest $request): RedirectResponse
    {
        try {
            $po = $this->service->create([
                'tanggal_po' => $request->input('tanggal_po'),
                'kode_vendor' => $request->input('kode_vendor'),
                'kode_unit' => $request->input('kode_unit'),
                'keterangan' => $request->input('keterangan'),
                'details' => $request->details(),
            ], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('purchase_orders.show', $po->id_po)->with('status', "Purchase Order {$po->nomor_po} berhasil dibuat.");
    }

    public function show(PurchaseOrder $purchase_order): View
    {
        $purchase_order->load(['details', 'vendor', 'unit']);

        return view('purchase-orders.show', ['po' => $purchase_order]);
    }

    /** Halaman cetak PO (standalone, auto window.print()). */
    public function print(PurchaseOrder $purchase_order): View
    {
        $purchase_order->load(['details', 'vendor', 'unit']);

        return view('purchase-orders.print', [
            'po' => $purchase_order,
            'company' => \App\Models\CompanySettings::find(1),
        ]);
    }

    public function cancel(PurchaseOrder $purchase_order): RedirectResponse
    {
        try {
            $this->service->cancel($purchase_order->id_po);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('purchase_orders.index')->with('status', "Purchase Order {$purchase_order->nomor_po} dibatalkan.");
    }

    private function opsi(): array
    {
        // Preview nomor PO (PO-YYMM-NNNN) berikutnya — indikatif.
        $base = \App\Services\Ledger\DocNumber::docBase('PO', now());
        $last = \App\Models\PurchaseOrder::where('nomor_po', 'like', $base.'%')
            ->orderByDesc('nomor_po')->value('nomor_po');

        return [
            'nomorPreview' => \App\Services\Ledger\DocNumber::nextDocNumber($base, $last),
            'vendorOptions' => ['' => '— pilih vendor —'] + Vendor::where('status', 'aktif')->orderBy('kode_vendor')->get()
                ->mapWithKeys(fn ($v) => [$v->kode_vendor => "{$v->kode_vendor} — {$v->nama_vendor}"])->all(),
            'unitOptions' => ['' => '— pilih unit —'] + BusinessUnit::where('status', 'aktif')->orderBy('kode_unit')->get()
                ->mapWithKeys(fn ($u) => [$u->kode_unit => "{$u->kode_unit} — {$u->nama_unit}"])->all(),
            'coaOptions' => CoaDetail::where('status', 'aktif')->orderBy('kode_coa')->get()
                ->map(fn ($c) => ['v' => $c->kode_coa, 'l' => "{$c->kode_coa} — {$c->nama_coa}"])->values()->all(),
        ];
    }
}
