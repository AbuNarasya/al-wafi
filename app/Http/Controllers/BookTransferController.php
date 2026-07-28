<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Http\Requests\BookTransferRequest;
use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Services\Modules\BookTransferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Pindah Buku (transfer antar rekening kas/bank). Controller tipis →
 * BookTransferService. Satu transaksi = satu jurnal (Debit tujuan, Kredit asal).
 */
class BookTransferController extends Controller
{
    public function __construct(private readonly BookTransferService $service) {}

    public function index(): View
    {
        return view('book-transfer.index', ['rows' => $this->service->list()]);
    }

    public function create(): View
    {
        return view('book-transfer.create', $this->opsi());
    }

    public function store(BookTransferRequest $request): RedirectResponse
    {
        try {
            $this->service->create([
                'tanggal' => $request->input('tanggal'),
                'kode_rekening_asal' => $request->input('kode_rekening_asal'),
                'kode_rekening_tujuan' => $request->input('kode_rekening_tujuan'),
                'kode_unit' => $request->input('kode_unit') ?: null,
                'nominal' => $request->input('nominal'),
                'keterangan' => $request->input('keterangan'),
            ], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('book_transfer.index')->with('status', 'Pindah buku berhasil diposting.');
    }

    public function void(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'max:255']]);

        try {
            $this->service->void($id, $data['alasan'], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('book_transfer.index')->with('status', 'Pindah buku berhasil di-void.');
    }

    private function opsi(): array
    {
        $rek = BankAccount::where('status', 'aktif')->with('coa')->orderBy('kode_coa')->get()
            ->mapWithKeys(fn ($r) => [$r->kode_coa => "{$r->nama_rekening} ({$r->kode_coa})"])->all();

        return [
            'rekeningOptions' => $rek,
            'unitOptions' => BusinessUnit::where('status', 'aktif')->orderBy('kode_unit')->get()
                ->mapWithKeys(fn ($u) => [$u->kode_unit => "{$u->kode_unit} — {$u->nama_unit}"])->all(),
        ];
    }
}
