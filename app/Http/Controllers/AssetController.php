<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\Asset;
use App\Models\CoaDetail;
use App\Services\Modules\AssetService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Aset Tetap (port assets module dev): CRUD + jalankan depresiasi bulanan.
 */
class AssetController extends Controller
{
    public function __construct(private AssetService $service = new AssetService) {}

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $rows = Asset::query()
            ->when($q !== '', fn ($query) => $query->where(
                fn ($w) => $w->where('kode_aset', 'ilike', "%{$q}%")->orWhere('nama_aset', 'ilike', "%{$q}%"),
            ))
            ->orderBy('kode_aset')->get();

        return view('assets.index', [
            'rows' => $rows, 'q' => $q, 'coaOptions' => $this->coaOptions(),
            'unitOptions' => ['' => '— Default modul —'] + \App\Models\BusinessUnit::where('status', 'aktif')->orderBy('kode_unit')->pluck('nama_unit', 'kode_unit')->all(),
        ]);
    }

    public function create(): View
    {
        return view('assets.form', [
            'aset' => new Asset(['status' => 'aktif', 'metode_depresiasi' => 'garis_lurus', 'akumulasi_depresiasi' => 0, 'nilai_residu' => 0]),
            'kategoriOptions' => $this->kategoriOptions(),
            'coaOptions' => $this->coaOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validasi($request, true);
        $data['kode_aset'] = $this->service->nextKode();
        Asset::create($data);

        return redirect()->route('assets.index')->with('status', "Aset {$data['kode_aset']} ditambahkan.");
    }

    public function edit(Asset $asset): View
    {
        return view('assets.form', ['aset' => $asset, 'kategoriOptions' => $this->kategoriOptions(), 'coaOptions' => $this->coaOptions()]);
    }

    public function update(Request $request, Asset $asset): RedirectResponse
    {
        $asset->update($this->validasi($request, false));

        return redirect()->route('assets.index')->with('status', 'Aset diperbarui.');
    }

    public function destroy(Asset $asset): RedirectResponse
    {
        try {
            $asset->delete();
        } catch (QueryException) {
            return back()->with('error', 'Aset tidak dapat dihapus karena masih dipakai transaksi.');
        }

        return redirect()->route('assets.index')->with('status', 'Aset dihapus.');
    }

    public function runDepreciation(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kode_coa_beban' => ['required', 'string', 'exists:coa_detail,kode_coa'],
            'kode_coa_akumulasi' => ['required', 'string', 'exists:coa_detail,kode_coa'],
            'kode_unit' => ['nullable', 'string', 'exists:business_units,kode_unit'],
        ]);

        try {
            $r = $this->service->runDepreciation($data, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('assets.index')->with('status', "Depresiasi diposting: {$r['referensi']} ({$r['jumlah_aset']} aset).");
    }

    private function validasi(Request $request, bool $isCreate): array
    {
        return $request->validate([
            'nama_aset' => ['required', 'string', 'max:255'],
            'kategori_aset' => ['required', 'string', 'max:255'],
            'kuantiti' => ['nullable', 'numeric', 'gt:0'],
            'harga_perolehan' => ['required', 'numeric', 'min:0'],
            'tanggal_perolehan' => ['required', 'date'],
            'umur_manfaat' => ['required', 'integer', 'gt:0'],
            'metode_depresiasi' => ['required', Rule::in(['garis_lurus', 'saldo_menurun'])],
            'nilai_residu' => ['nullable', 'numeric', 'min:0'],
            'akumulasi_depresiasi' => [$isCreate ? 'nullable' : 'prohibited', 'numeric', 'min:0'],
            'kode_coa' => ['nullable', 'string', 'exists:coa_detail,kode_coa'],
            'status' => ['required', Rule::in(['draft', 'aktif', 'dilepas'])],
        ]);
    }

    private function coaOptions(): array
    {
        return ['' => '— pilih akun —'] + CoaDetail::where('status', 'aktif')->orderBy('kode_coa')->get()
            ->mapWithKeys(fn ($c) => [$c->kode_coa => "{$c->kode_coa} — {$c->nama_coa}"])->all();
    }

    private function kategoriOptions(): array
    {
        // Kategori disimpan sbg teks (kategori_aset); pilih dari master aktif.
        $master = \App\Models\AssetCategory::where('status', 'aktif')->orderBy('nama')
            ->pluck('nama', 'nama')->all();

        return ['' => '— pilih kategori —'] + $master;
    }
}
