<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\BusinessUnit;
use App\Models\CompanySettings;
use App\Models\Inventory;
use App\Models\LokasiPersediaan;
use App\Models\PenerimaanBarang;
use App\Models\PurchaseOrder;
use App\Models\Vendor;
use App\Services\Modules\PenerimaanBarangService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Penerimaan Barang — petugas gudang/toko mencatat barang yang tiba. Stok masuk di
 * lokasinya saat disimpan; hutang menyusul saat invoice vendor menagihnya.
 * Logika & jurnal: [[App\Services\Modules\PenerimaanBarangService]].
 */
class PenerimaanBarangController extends Controller
{
    public function __construct(private readonly PenerimaanBarangService $service) {}

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $fStatus = (string) $request->query('status', '');
        $fTagih = (string) $request->query('tagih', '');

        $rows = PenerimaanBarang::query()->with(['vendor', 'lokasi', 'details'])
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('nomor', 'ilike', "%{$q}%")
                ->orWhere('nomor_surat_jalan', 'ilike', "%{$q}%")))
            ->when($fStatus !== '', fn ($w) => $w->where('status', $fStatus))
            ->when($fTagih === 'belum', fn ($w) => $w->where('status', 'aktif')
                ->whereHas('details', fn ($d) => $d->whereColumn('qty_ditagih', '<', 'kuantiti')))
            ->orderByDesc('tanggal')->orderByDesc('id_penerimaan')
            ->paginate(25)->withQueryString();

        // Total barang yang sudah di rak tetapi tagihannya belum datang = saldo akun GRNI.
        $belumDitagih = '0';
        PenerimaanBarang::with('details')->where('status', 'aktif')->get()
            ->each(function ($p) use (&$belumDitagih) {
                foreach ($p->details as $d) {
                    $belumDitagih = Money::add($belumDitagih, $d->sisaNilai());
                }
            });

        return view('penerimaan-barang.index', [
            'rows' => $rows, 'q' => $q,
            'filter' => ['status' => $fStatus, 'tagih' => $fTagih],
            'belumDitagih' => $belumDitagih,
            'akunSiap' => (bool) CompanySettings::find(1)?->kode_coa_grni,
        ]);
    }

    public function create(Request $request): View
    {
        $po = $request->query('po') ? PurchaseOrder::with('details')->find($request->query('po')) : null;
        if ($po && ! in_array($po->status, ['open', 'sebagian'], true)) {
            $po = null;
        }

        $items = Inventory::with('satuanLain')->where('status', 'aktif')->orderBy('nama_persediaan')->get();
        $itemOpts = $items->map(fn ($i) => [
            'v' => $i->kode_persediaan,
            'l' => "{$i->nama_persediaan} ({$i->satuan})",
            'harga' => (string) (int) $i->harga_perolehan,
            'satuan' => array_merge(
                [['s' => $i->satuan, 'isi' => '1']],
                $i->satuanLain->map(fn ($s) => ['s' => $s->satuan, 'isi' => rtrim(rtrim((string) $s->isi, '0'), '.')])->all(),
            ),
        ])->values()->all();

        // Baris dari PO: hanya barang yang masih bersisa, dan yang belum ditagih langsung.
        $rows = [];
        if ($po) {
            foreach ($po->details as $d) {
                $sisa = Money::sub($d->kuantiti, $d->qty_diterima, 4);
                $langsung = Money::gtZero($d->qty_invoiced, 4) && ! Money::gtZero($d->qty_diterima, 4);
                if (! $d->kode_persediaan || ! Money::gtZero($sisa, 4) || $langsung) {
                    continue;
                }
                $rows[] = [
                    'id_po_detail' => $d->id, 'kode_persediaan' => $d->kode_persediaan, 'satuan' => '',
                    'kuantiti' => rtrim(rtrim($sisa, '0'), '.'), 'sisa' => rtrim(rtrim($sisa, '0'), '.'),
                    'harga_satuan' => (string) (int) $d->harga_satuan, 'keterangan' => '',
                ];
            }
        }

        return view('penerimaan-barang.create', [
            'po' => $po,
            'initRows' => old('details', $rows ?: [['id_po_detail' => '', 'kode_persediaan' => '', 'satuan' => '', 'kuantiti' => '1', 'sisa' => '', 'harga_satuan' => '', 'keterangan' => '']]),
            'itemOpts' => $itemOpts,
            'poOptions' => ['' => '— tanpa PO (beli langsung) —'] + PurchaseOrder::whereIn('status', ['open', 'sebagian'])
                ->whereHas('details', fn ($d) => $d->whereNotNull('kode_persediaan')->whereColumn('qty_diterima', '<', 'kuantiti'))
                ->with('vendor')->orderByDesc('id_po')->get()
                ->mapWithKeys(fn ($p) => [$p->id_po => "{$p->nomor_po} — {$p->vendor?->nama_vendor}"])->all(),
            'vendorOptions' => ['' => '— pilih vendor —'] + Vendor::where('status', 'aktif')->orderBy('nama_vendor')->pluck('nama_vendor', 'kode_vendor')->all(),
            'lokasiOptions' => LokasiPersediaan::pilihan(),
            'unitOptions' => ['' => '— ikut PO / lokasi —'] + BusinessUnit::where('status', 'aktif')->orderBy('nama_unit')->pluck('nama_unit', 'kode_unit')->all(),
            'akunSiap' => (bool) CompanySettings::find(1)?->kode_coa_grni,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // Baris yang jumlahnya dikosongkan/nol = barang PO yang belum datang hari ini.
        $request->merge(['details' => array_values(array_filter((array) $request->input('details', []),
            fn ($d) => ($d['kode_persediaan'] ?? '') !== '' && (float) ($d['kuantiti'] ?? 0) > 0))]);

        $data = $request->validate([
            'tanggal' => ['required', 'date'],
            'id_po' => ['nullable', 'integer', 'exists:purchase_orders,id_po'],
            'kode_vendor' => ['required', 'string', 'exists:vendors,kode_vendor'],
            'kode_lokasi' => ['required', 'string', Rule::exists('lokasi_persediaan', 'kode')->where('status', 'aktif')],
            'kode_unit' => ['nullable', 'string', 'exists:business_units,kode_unit'],
            'nomor_surat_jalan' => ['nullable', 'string', 'max:100'],
            'keterangan' => ['nullable', 'string', 'max:500'],
            'details' => ['required', 'array', 'min:1'],
            'details.*.id_po_detail' => ['nullable', 'integer', 'exists:purchase_order_details,id'],
            'details.*.kode_persediaan' => ['required', 'string', 'exists:inventory,kode_persediaan'],
            'details.*.satuan' => ['nullable', 'string', 'max:50'],
            'details.*.kuantiti' => ['required', 'numeric', 'gt:0'],
            'details.*.harga_satuan' => ['required', 'numeric', 'min:0'],
            'details.*.keterangan' => ['nullable', 'string', 'max:255'],
        ], ['details.required' => 'Belum ada barang yang diterima (jumlah semua baris nol).']);

        try {
            $doc = $this->service->create($data, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('penerimaan_barang.show', $doc->id_penerimaan)
            ->with('status', "Penerimaan {$doc->nomor} disimpan — stok bertambah di {$doc->lokasi->nama}.");
    }

    public function show(PenerimaanBarang $penerimaan): View
    {
        $penerimaan->load(['details.persediaan', 'vendor', 'lokasi', 'unit', 'po', 'pengguna']);

        return view('penerimaan-barang.show', [
            'doc' => $penerimaan,
            'invoice' => \App\Models\InvoiceDetail::with('invoice')
                ->whereIn('id_penerimaan_detail', $penerimaan->details->pluck('id'))
                ->get()->pluck('invoice')->unique('id_invoice')->values(),
        ]);
    }

    public function batal(Request $request, PenerimaanBarang $penerimaan): RedirectResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'max:255']]);

        try {
            $this->service->batal($penerimaan->id_penerimaan, $data['alasan'], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('penerimaan_barang.show', $penerimaan->id_penerimaan)
            ->with('status', "Penerimaan {$penerimaan->nomor} dibatalkan — stok & jurnalnya ditarik kembali.");
    }
}
