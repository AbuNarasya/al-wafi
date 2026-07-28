<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\CoaDetail;
use App\Services\Modules\OpeningBalanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Saldo Awal (port opening-balance dev): kelola baris → finalisasi jadi jurnal
 * pembuka → void untuk revisi.
 */
class OpeningBalanceController extends Controller
{
    public function __construct(private OpeningBalanceService $service = new OpeningBalanceService) {}

    public function index(): View
    {
        $state = $this->service->state();

        return view('opening-balance.index', [
            'rows' => $state['rows'],
            'summary' => $state['summary'],
            'coaOptions' => ['' => '— pilih akun —'] + CoaDetail::where('status', 'aktif')->orderBy('kode_coa')->get()
                ->mapWithKeys(fn ($c) => [$c->kode_coa => "{$c->kode_coa} — {$c->nama_coa}"])->all(),
        ]);
    }

    public function addLine(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kode_coa' => ['required', 'string', 'exists:coa_detail,kode_coa'],
            'jenis_saldo' => ['required', Rule::in(['debet', 'kredit'])],
            'saldo' => ['required', 'numeric', 'gt:0'],
        ]);

        try {
            $this->service->addLine($data);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Baris saldo awal ditambahkan.');
    }

    public function removeLine(int $id): RedirectResponse
    {
        try {
            $this->service->removeLine($id);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Baris dihapus.');
    }

    public function post(Request $request): RedirectResponse
    {
        try {
            $entry = $this->service->post($request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "Saldo awal difinalisasi (jurnal {$entry->referensi}).");
    }

    public function void(Request $request): RedirectResponse
    {
        try {
            $this->service->void($request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Finalisasi saldo awal di-void; baris dapat diedit lagi.');
    }
}
