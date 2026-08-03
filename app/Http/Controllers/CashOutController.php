<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Http\Requests\CashOutRequest;
use App\Models\Bagian;
use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CashOut;
use App\Models\CoaDetail;
use App\Models\Vendor;
use App\Services\Modules\CashOutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Kas Keluar (Payment Voucher). Controller tipis → CashOutService (jenis
 * "lainnya"). Jurnal: Debit tiap akun rincian; Kredit Kas/Bank.
 */
class CashOutController extends Controller
{
    public function __construct(private readonly CashOutService $service) {}

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $fVendor = trim((string) $request->query('vendor', ''));
        $fStatus = trim((string) $request->query('status', ''));

        $rows = CashOut::query()->with(['vendor', 'unit'])
            ->when($q !== '', fn ($query) => $query->where(
                fn ($w) => $w->where('nomor_transaksi', 'ilike', "%{$q}%")->orWhere('keterangan', 'ilike', "%{$q}%"),
            ))
            ->when($fVendor !== '', fn ($query) => $query->where('kode_vendor', $fVendor))
            ->when($fStatus !== '', fn ($query) => $query->where('status', $fStatus))
            ->orderByDesc('tanggal')->orderByDesc('kode_transaksi')
            ->paginate(25)->withQueryString();

        return view('cash-out.index', [
            'rows' => $rows,
            'q' => $q,
            'filter' => ['vendor' => $fVendor, 'status' => $fStatus],
            'opsiVendor' => \App\Models\Vendor::orderBy('nama_vendor')->pluck('nama_vendor', 'kode_vendor')->all(),
            'opsiStatus' => ['aktif' => 'Aktif', 'void' => 'Void'],
        ]);
    }

    /**
     * Formulir Kas Keluar. Bila datang dari Perintah Pembayaran (`?perintah=`),
     * barisnya TERISI OTOMATIS dari kewajiban yang masih bersisa di sana.
     *
     * Tanpa pengisian otomatis ini, modul Perintah Pembayaran justru MENAMBAH
     * pekerjaan admin — mengetik hal yang sama dua kali — dan itu keluhan yang
     * akan muncul di minggu pertama.
     */
    public function create(Request $request): View
    {
        $data = $this->opsi();
        $data['perintah'] = null;
        $data['prefill'] = [];

        $idPerintah = (int) $request->query('perintah', 0);
        if ($idPerintah > 0) {
            $pp = \App\Models\PerintahPembayaran::with('detail')->find($idPerintah);
            if ($pp && $pp->bolehDibayar()) {
                $data['perintah'] = $pp;
                $data['prefill'] = $this->barisDariPerintah($pp);
            }
        }

        return view('cash-out.create', $data);
    }

    /**
     * Baris formulir dari kewajiban PP yang masih bersisa.
     *
     * Pembiayaan bank dipetakan ke baris `lainnya` beratasnamakan akun hutang
     * pinjamannya — begitulah Kas Keluar mencatat angsuran; penanda pinjamannya
     * sendiri ada di kepala voucher.
     *
     * @return list<array<string,mixed>>
     */
    private function barisDariPerintah(\App\Models\PerintahPembayaran $pp): array
    {
        $baris = [];
        foreach ($pp->detail as $d) {
            if ($d->status_baris !== 'disetujui' || ! \App\Support\Money::gtZero($d->sisa)) {
                continue;
            }
            $row = [
                'tipe' => 'lainnya', 'kode_coa' => '', 'id_invoice' => '', 'id_pengajuan' => '',
                'kode_persediaan' => '', 'kuantiti' => '', 'harga_satuan' => '',
                'nominal' => (string) \App\Support\Money::of($d->sisa),
                'keterangan' => $d->keterangan ?: $d->nomor_dokumen,
                'kode_bagian' => '', 'aset_pilih' => '',
                'id_perintah_detail' => $d->id,
                'label_perintah' => $d->nomor_dokumen.($d->pihak ? ' · '.$d->pihak : ''),
            ];

            if ($d->sumber === 'invoice') {
                $row['tipe'] = 'invoice';
                $row['id_invoice'] = (string) $d->id_dokumen;
            } elseif ($d->sumber === 'pengajuan') {
                $row['tipe'] = 'pengajuan';
                $row['id_pengajuan'] = (string) $d->id_dokumen;
            } elseif ($d->sumber === 'bank_loan') {
                $loan = \App\Models\BankLoan::find($d->id_dokumen);
                $row['kode_coa'] = $loan?->kode_coa_hutang ?? '';
            }

            $baris[] = $row;
        }

        return $baris;
    }

    public function store(CashOutRequest $request): RedirectResponse
    {
        try {
            $rec = $this->service->create([
                'tanggal' => $request->input('tanggal'),
                'kode_unit' => $request->input('kode_unit') ?: null,
                'kode_rekening' => $request->input('kode_rekening'),
                'kode_vendor' => $request->input('kode_vendor') ?: null,
                'referensi' => $request->input('referensi'),
                'keterangan' => $request->input('keterangan'),
                'id_bank_loan' => $request->input('id_bank_loan') ?: null,
                'id_perintah' => $request->input('id_perintah') ?: null,
                'metode' => $request->input('metode') ?: null,
                'details' => $request->details(),
            ], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('cash_out.show', $rec->kode_transaksi)->with('status', "Kas Keluar {$rec->nomor_transaksi} berhasil diposting.");
    }

    public function show(CashOut $cash_out): View
    {
        $cash_out->load(['details', 'vendor', 'unit', 'rekening', 'user']);

        return view('cash-out.show', ['rec' => $cash_out]);
    }

    /** Bukti Kas Keluar siap tanda tangan (view cetaknya dipakai bersama Kas Masuk). */
    public function print(CashOut $cash_out): View
    {
        $cash_out->load(['details', 'vendor', 'unit', 'rekening', 'user']);

        return view('cash.print', [
            'rec' => $cash_out,
            'jenis' => 'keluar',
            'company' => \App\Models\CompanySettings::find(1),
        ]);
    }

    public function void(Request $request, CashOut $cash_out): RedirectResponse
    {
        $request->validate(['alasan' => ['required', 'string', 'max:255']]);

        try {
            $this->service->void(
                $cash_out->kode_transaksi,
                ['tanggal' => now()->toDateString(), 'alasan' => $request->input('alasan')],
                $request->user()->id_pengguna,
                $request->user()->nama,
            );
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('cash_out.index')->with('status', "Kas Keluar {$cash_out->nomor_transaksi} berhasil di-void.");
    }

    private function opsi(): array
    {
        // Preview nomor PV (KK-YYMM-NNNN) berikutnya — indikatif.
        $base = \App\Services\Ledger\DocNumber::docBase('KK', now());
        $last = \App\Models\CashOut::where('nomor_transaksi', 'like', $base.'%')
            ->orderByDesc('nomor_transaksi')->value('nomor_transaksi');

        return [
            'nomorPreview' => \App\Services\Ledger\DocNumber::nextDocNumber($base, $last),
            'unitOptions' => BusinessUnit::where('status', 'aktif')->orderBy('kode_unit')->get()
                ->mapWithKeys(fn ($u) => [$u->kode_unit => "{$u->kode_unit} — {$u->nama_unit}"])->all(),
            'rekeningOptions' => BankAccount::where('status', 'aktif')->with('coa')->orderBy('kode_coa')->get()
                ->mapWithKeys(fn ($r) => [$r->kode_coa => "{$r->nama_rekening} ({$r->kode_coa})"])->all(),
            'vendorOptions' => ['' => '— Tanpa vendor —'] + Vendor::where('status', 'aktif')->orderBy('kode_vendor')->get()
                ->mapWithKeys(fn ($v) => [$v->kode_vendor => "{$v->kode_vendor} — {$v->nama_vendor}"])->all(),
            'coaOptions' => CoaDetail::where('status', 'aktif')->orderBy('kode_coa')->get()
                ->map(fn ($c) => ['v' => $c->kode_coa, 'l' => "{$c->kode_coa} — {$c->nama_coa}"])->values()->all(),
            'bagianOptions' => Bagian::where('status', 'aktif')->orderBy('kode_bagian')->get()
                ->map(fn ($b) => ['v' => $b->kode_bagian, 'l' => "{$b->kode_bagian} — {$b->nama_bagian}"])->values()->all(),

            // Invoice belum lunas (utk baris tipe invoice) — id/nomor/vendor/sisa.
            'invoiceData' => \App\Models\Invoice::where('status', '!=', 'void')->where('sisa_hutang', '>', 0)
                ->orderByDesc('id_invoice')->get(['id_invoice', 'nomor_invoice', 'kode_vendor', 'sisa_hutang'])
                ->map(fn ($i) => ['id' => $i->id_invoice, 'nomor' => $i->nomor_invoice, 'vendor' => $i->kode_vendor, 'sisa' => \App\Support\Money::of($i->sisa_hutang)])->all(),

            // Persediaan (utk baris tipe inventory) — hanya yg punya akun COA.
            'inventoryOptions' => \App\Models\Inventory::where('status', 'aktif')->whereNotNull('kode_coa')
                ->orderBy('nama_persediaan')->get(['kode_persediaan', 'nama_persediaan'])
                ->map(fn ($it) => ['v' => $it->kode_persediaan, 'l' => "{$it->nama_persediaan} ({$it->kode_persediaan})"])->all(),

            // Pengajuan siap bayar (diposting=accrual, diverifikasi=uang muka).
            'pengajuanData' => \App\Models\PengajuanPembayaran::whereIn('status', ['diposting', 'diverifikasi'])
                ->orderByDesc('id')->get(['id', 'nomor', 'jenis', 'nominal', 'sisa_hutang'])
                ->map(fn ($p) => ['id' => $p->id, 'nomor' => $p->nomor, 'jenis' => $p->jenis, 'nominal' => \App\Support\Money::of($p->nominal), 'sisa' => \App\Support\Money::of($p->sisa_hutang)])->all(),

            // Pembiayaan aktif (Angsuran) + data prefill baris.
            'loanOptions' => ['' => '— bukan angsuran pembiayaan —'] + \App\Models\BankLoan::where('status', 'aktif')
                ->orderBy('nama_bank')->get()->mapWithKeys(fn ($l) => [$l->id => "{$l->nama_bank} — sisa pokok ".\App\Support\Money::of($l->sisa_pokok)])->all(),
            'loanData' => \App\Models\BankLoan::where('status', 'aktif')->get()
                ->map(fn ($l) => ['id' => $l->id, 'kode_coa_hutang' => $l->kode_coa_hutang, 'kode_coa_beban' => $l->kode_coa_beban_bunga])->all(),

            // Perlakuan aset per baris "lainnya": buat draft baru atau tambah nilai ke aset yang ada.
            'asetOptions' => array_merge(
                [['v' => '__new__', 'l' => '➕ Buat aset baru (draft)']],
                \App\Models\Asset::orderBy('kode_aset')->get()
                    ->map(fn ($a) => ['v' => $a->kode_aset, 'l' => "{$a->kode_aset} — {$a->nama_aset}"])->values()->all(),
            ),
        ];
    }
}
