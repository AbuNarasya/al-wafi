<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\CompanySettings;
use App\Models\JournalLine;
use App\Services\Modules\PenerimaanAlWafiService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Penerimaan pencairan belanja kartu dari ERP Al Wafi (instans Wafi Mart, Fase 3c-3). Hak: penerimaan-al-wafi
 * lihat / buat. Logika: [[PenerimaanAlWafiService]].
 */
class PenerimaanAlWafiController extends Controller
{
    public function __construct(private readonly PenerimaanAlWafiService $service) {}

    public function index(): View
    {
        $galat = null;
        try {
            $daftar = $this->service->daftar();
        } catch (AppException $e) {
            $daftar = [];
            $galat = 'Daftar pencairan tak bisa dimuat dari ERP Al Wafi: '.$e->getMessage();
        }
        $piutang = CompanySettings::query()->value('kode_coa_kartu_santri');

        return view('penerimaan-al-wafi.index', [
            'daftar' => $daftar, 'galat' => $galat,
            // Saldo Piutang ke Al Wafi menurut buku Wafi Mart = belanja kartu yang belum dibayar Al Wafi.
            'saldoPiutang' => $piutang ? Money::sub(JournalLine::where('kode_coa', $piutang)->sum('debet'), JournalLine::where('kode_coa', $piutang)->sum('kredit')) : null,
            'rekening' => BankAccount::where('status', 'aktif')->orderBy('kode_coa')->get()
                ->mapWithKeys(fn ($b) => [$b->kode_coa => "{$b->kode_coa} — {$b->nama_rekening}"])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'nomor' => ['required', 'string', 'max:30'],
            'kode_coa_bank' => ['required', 'string', 'exists:bank_accounts,kode_coa'],
        ]);
        try {
            $doc = $this->service->terima($data['nomor'], $data['kode_coa_bank'], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "Penerimaan {$doc->nomor_pencairan} Rp ".number_format((float) $doc->total, 0, ',', '.').' dibukukan.');
    }
}
