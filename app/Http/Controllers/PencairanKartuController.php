<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\LokasiPersediaan;
use App\Models\PencairanKartu;
use App\Services\Modules\PencairanKartuService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Pencairan belanja kartu santri ke kas toko (mingguan). Hak: pencairan-kartu
 * lihat / buat (mencairkan) / hapus (membatalkan). Logika: [[PencairanKartuService]].
 */
class PencairanKartuController extends Controller
{
    public function __construct(private readonly PencairanKartuService $service) {}

    public function index(Request $request): View
    {
        $sampai = $request->query('sampai') ?: now()->toDateString();
        $toko = LokasiPersediaan::where('jenis', 'toko')->orderBy('urutan')->get();

        return view('pencairan-kartu.index', [
            'sampai' => $sampai,
            // Ringkasan per toko: berapa yang menunggu dicairkan sampai tanggal itu.
            'menunggu' => $toko->map(fn ($t) => ['toko' => $t, ...$this->service->menunggu($t->kode, $sampai)])
                ->filter(fn ($m) => $m['penjualan']->isNotEmpty() || $m['retur']->isNotEmpty())->values(),
            'rows' => PencairanKartu::with(['lokasi', 'pembuat'])->orderByDesc('id_pencairan')->paginate(25),
            'rekening' => BankAccount::where('status', 'aktif')->orderBy('kode_coa')->get()
                ->mapWithKeys(fn ($b) => [$b->kode_coa => "{$b->kode_coa} — {$b->nama_rekening}"])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kode_lokasi' => ['required', 'string', 'exists:lokasi_persediaan,kode'],
            'sampai_tanggal' => ['required', 'date', 'before_or_equal:today'],
            'kode_coa_sumber' => ['required', 'string', 'exists:bank_accounts,kode_coa'],
            'kode_coa_tujuan' => ['required', 'string', 'exists:bank_accounts,kode_coa', 'different:kode_coa_sumber'],
        ], ['kode_coa_tujuan.different' => 'Rekening sumber dan tujuan tidak boleh sama.']);

        try {
            $doc = $this->service->cairkan($data['kode_lokasi'], $data['sampai_tanggal'], $data['kode_coa_sumber'], $data['kode_coa_tujuan'], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('status', "Pencairan {$doc->nomor} Rp ".number_format((float) $doc->total, 0, ',', '.').' dibukukan — transfer uangnya ke rekening toko.');
    }

    public function batal(Request $request, PencairanKartu $pencairan): RedirectResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'max:255']]);

        try {
            $this->service->batal($pencairan->id_pencairan, $data['alasan'], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "Pencairan {$pencairan->nomor} dibatalkan — struknya kembali menunggu dicairkan.");
    }
}
