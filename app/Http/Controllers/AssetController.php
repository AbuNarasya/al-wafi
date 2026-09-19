<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\Bagian;
use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\PelepasanAset;
use App\Services\Modules\AssetService;
use App\Services\Modules\PelepasanAsetService;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Aset Tetap: CRUD, depresiasi bulanan, dan PELEPASAN (jual / hibah / hapus).
 *
 * Menghapus baris aset kini dibatasi pada yang benar-benar belum tersentuh.
 * Aset yang nilainya sudah masuk buku besar harus dilepas lewat dokumen
 * berjurnal — lihat [[PelepasanAsetService]].
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
            'unitOptions' => ['' => '— Default modul —'] + BusinessUnit::where('status', 'aktif')->orderBy('kode_unit')->pluck('nama_unit', 'kode_unit')->all(),
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

    /**
     * Hapus aset — HANYA untuk baris yang salah ketik dan belum tersentuh apa
     * pun. Aset yang sudah disusutkan, sudah punya pergerakan nilai, atau sudah
     * dilepas TIDAK boleh dihapus: nilainya sudah ada di buku besar, dan
     * menghapus barisnya hanya membuat register aset bercerai dari pembukuan
     * tanpa satu pun gejala. Yang benar adalah melepasnya lewat menu Pelepasan
     * Aset, supaya ada jurnalnya.
     */
    public function destroy(Asset $asset): RedirectResponse
    {
        if ($asset->status === 'dilepas') {
            return back()->with('error', 'Aset ini sudah dilepas dan menjadi bagian riwayat pembukuan; tidak bisa dihapus.');
        }
        if (Money::gtZero($asset->akumulasi_depresiasi)) {
            return back()->with('error',
                "Aset \"{$asset->nama_aset}\" sudah disusutkan (akumulasi ".Money::of($asset->akumulasi_depresiasi).'), '
                .'jadi nilainya sudah ada di buku besar. Pakai menu Pelepasan Aset agar ada jurnalnya.');
        }
        if ($asset->movements()->exists()) {
            return back()->with('error',
                "Aset \"{$asset->nama_aset}\" sudah punya pergerakan nilai dari transaksi. "
                .'Pakai menu Pelepasan Aset agar ada jurnalnya.');
        }

        try {
            $asset->delete();
        } catch (QueryException) {
            return back()->with('error', 'Aset tidak dapat dihapus karena masih dipakai transaksi.');
        }

        return redirect()->route('assets.index')->with('status', 'Aset dihapus.');
    }

    // ---- Pelepasan aset ----

    public function pelepasanIndex(Request $request): View
    {
        return view('assets.pelepasan', [
            'rows' => PelepasanAset::with(['aset', 'unit'])->orderByDesc('id')->paginate(25)->withQueryString(),
            'asetOptions' => Asset::where('status', 'aktif')->orderBy('kode_aset')->get()
                ->mapWithKeys(fn ($a) => [$a->kode_aset => "{$a->kode_aset} — {$a->nama_aset}"])->all(),
            'coaOptions' => CoaDetail::where('status', 'aktif')->orderBy('kode_coa')->get()
                ->mapWithKeys(fn ($c) => [$c->kode_coa => "{$c->kode_coa} — {$c->nama_coa}"])->all(),
            'rekeningOptions' => BankAccount::where('status', 'aktif')->orderBy('kode_coa')->get()
                ->mapWithKeys(fn ($b) => [$b->kode_coa => "{$b->kode_coa} — {$b->nama_rekening}"])->all(),
            'unitOptions' => ['' => '— tanpa unit —'] + BusinessUnit::where('status', 'aktif')
                ->orderBy('kode_unit')->pluck('nama_unit', 'kode_unit')->all(),
            'bagianOptions' => ['' => '— tanpa bagian —'] + Bagian::where('status', 'aktif')
                ->orderBy('nama_bagian')->pluck('nama_bagian', 'kode_bagian')->all(),
            'perlakuanOptions' => PelepasanAset::PERLAKUAN,
        ]);
    }

    public function lepas(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'kode_aset' => ['required', 'string', 'exists:assets,kode_aset'],
            'tanggal' => ['required', 'date'],
            'perlakuan' => ['required', Rule::in(array_keys(PelepasanAset::PERLAKUAN))],
            'harga_jual' => ['required_if:perlakuan,dijual', 'nullable', 'numeric', 'min:0'],
            'kode_rekening' => ['required_if:perlakuan,dijual', 'nullable', 'string', 'exists:bank_accounts,kode_coa'],
            'kode_coa_akumulasi' => ['required', 'string', 'exists:coa_detail,kode_coa'],
            'kode_coa_labarugi' => ['required', 'string', 'exists:coa_detail,kode_coa'],
            'kode_unit' => ['nullable', 'string', 'exists:business_units,kode_unit'],
            'kode_bagian' => ['nullable', 'string', 'exists:bagian,kode_bagian'],
            'alasan' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $dok = (new PelepasanAsetService)->lepas($d, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('assets.pelepasan')
            ->with('status', "{$dok->labelPerlakuan()}: {$dok->aset->nama_aset} dilepas ({$dok->nomor_ref}).");
    }

    public function voidPelepasan(Request $request, int $id): RedirectResponse
    {
        $d = $request->validate(['alasan' => ['required', 'string', 'max:255']]);

        try {
            $dok = (new PelepasanAsetService)->void($id, $d['alasan'], $request->user()->id_pengguna, $request->user()->nama);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('assets.pelepasan')
            ->with('status', "Pelepasan {$dok->nomor_ref} dibatalkan; asetnya aktif kembali.");
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
            // Aset pindahan sistem: nilainya BELUM ada di buku besar, jadi ia
            // ikut dihitung sebagai baris turunan di menu Saldo Awal. Aset yang
            // dibeli lewat Kas Keluar TIDAK boleh ditandai — nilainya sudah masuk
            // buku besar dari sisi pembayarannya, dan menandainya membuat
            // angkanya terhitung dua kali.
            //
            // Hanya saat membuat. Mengubahnya di kemudian hari berarti menggeser
            // neraca pembuka tanpa jejak dokumen apa pun.
            'saldo_awal' => [$isCreate ? 'nullable' : 'prohibited', 'boolean'],
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
        $master = AssetCategory::where('status', 'aktif')->orderBy('nama')
            ->pluck('nama', 'nama')->all();

        return ['' => '— pilih kategori —'] + $master;
    }
}
