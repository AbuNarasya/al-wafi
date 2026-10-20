<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\PencairanToko;
use App\Services\Modules\JurnalDompetToko;
use App\Services\Modules\PencairanTokoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Pencairan belanja kartu santri ke Wafi Mart (instans Al Wafi, Fase 3c-3). Hak: pencairan-toko lihat / buat
 * (mencairkan + jalankan jurnal malam sekarang) / hapus (membatalkan). Logika: [[PencairanTokoService]].
 */
class PencairanTokoController extends Controller
{
    public function __construct(private readonly PencairanTokoService $service) {}

    public function index(Request $request): View
    {
        $sampai = $request->query('sampai') ?: now()->subDay()->toDateString();

        return view('pencairan-toko.index', [
            'sampai' => $sampai,
            'menunggu' => $this->service->menunggu($sampai),
            'rows' => PencairanToko::with('pembuat')->orderByDesc('id')->paginate(25),
            'rekening' => BankAccount::where('status', 'aktif')->orderBy('kode_coa')->get()
                ->mapWithKeys(fn ($b) => [$b->kode_coa => "{$b->kode_coa} — {$b->nama_rekening}"])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sampai_tanggal' => ['required', 'date', 'before_or_equal:today'],
            'kode_coa_bank' => ['required', 'string', 'exists:bank_accounts,kode_coa'],
        ]);
        try {
            $doc = $this->service->cairkan($data['sampai_tanggal'], $data['kode_coa_bank'], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('status', "Pencairan {$doc->nomor} Rp ".number_format((float) $doc->total, 0, ',', '.')
            .' dibukukan — transfer uangnya ke rekening Wafi Mart; Wafi Mart mencatat penerimaannya dengan nomor yang sama.');
    }

    /** Jurnal malam belanja kartu dijalankan sekarang (bila cron belum menyala) — hanya tanggal sebelum hari ini. */
    public function jurnal(JurnalDompetToko $jurnal): RedirectResponse
    {
        try {
            $hasil = $jurnal->jalankan();
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', $hasil ? count($hasil).' tanggal dijurnal.' : 'Tidak ada transaksi yang menunggu dijurnal.');
    }

    public function batal(Request $request, int $pencairan): RedirectResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'max:255']]);
        try {
            $doc = $this->service->batal($pencairan, $data['alasan'], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "Pencairan {$doc->nomor} dibatalkan, jurnalnya dibalik.");
    }
}
