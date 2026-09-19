<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\Bagian;
use App\Models\CoaDetail;
use App\Models\Inventory;
use App\Models\LapisanPersediaan;
use App\Services\Modules\PersediaanService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Master Persediaan + dua pekerjaan gudang yang berjurnal (Pemakaian & Opname)
 * + kartu stok.
 *
 * PEMBAGIAN PERAN: yang ditetapkan BAGIAN KEUANGAN di master hanyalah AKUN —
 * akun persediaan, akun beban pemakaian, akun selisih opname. Petugas gudang
 * menyebut barang, tanggal, jumlah, dan BAGIAN yang memakainya; ia tak pernah
 * diminta memilih akun.
 *
 * Stok bukan lagi kolom yang bisa diketik. Ia turunan dari lapisan FIFO —
 * lihat [[App\Services\Ledger\InventoryMovement]].
 */
class InventoryController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $rows = Inventory::query()
            ->when($q !== '', fn ($query) => $query->where(
                fn ($w) => $w->where('kode_persediaan', 'ilike', "%{$q}%")->orWhere('nama_persediaan', 'ilike', "%{$q}%"),
            ))
            ->orderBy('kode_persediaan')->get();

        return view('inventory.index', [
            'rows' => $rows,
            'q' => $q,
            // Dipakai pemilih "Bagian pemakai" pada formulir Pemakaian/Opname.
            'bagianOptions' => Bagian::orderBy('nama_bagian')->pluck('nama_bagian', 'kode_bagian')->all(),
        ]);
    }

    public function create(): View
    {
        return view('inventory.form', [
            'item' => new Inventory(['status' => 'aktif', 'stok_masuk' => 0, 'stok_keluar' => 0]),
            'coaOptions' => $this->coaOptions(),
            'bagianOptions' => $this->bagianOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validasi($request, true);
        $data['kode_persediaan'] = $this->nextKode();
        Inventory::create($data);

        return redirect()->route('inventory.index')->with('status', "Persediaan {$data['kode_persediaan']} ditambahkan.");
    }

    public function edit(Inventory $inventory): View
    {
        return view('inventory.form', [
            'item' => $inventory,
            'coaOptions' => $this->coaOptions(),
            'bagianOptions' => $this->bagianOptions(),
        ]);
    }

    public function update(Request $request, Inventory $inventory): RedirectResponse
    {
        // Stok TIDAK diubah di sini — ia turunan kartu stok. Pakai Pemakaian/Opname.
        $data = $this->validasi($request, false);
        $inventory->update($data);

        return redirect()->route('inventory.index')->with('status', 'Persediaan diperbarui.');
    }

    public function destroy(Inventory $inventory): RedirectResponse
    {
        try {
            $inventory->delete();
        } catch (QueryException) {
            return back()->with('error', 'Persediaan tidak dapat dihapus karena masih dipakai transaksi.');
        }

        return redirect()->route('inventory.index')->with('status', 'Persediaan dihapus.');
    }

    /**
     * Mutasi stok gudang — BERJURNAL, dua pekerjaan saja:
     *
     *  - `pemakaian` → jumlah yang dipakai; D Beban Pemakaian / K Persediaan.
     *  - `opname`    → STOK HASIL HITUNG; sistem yang menentukan arah & besarnya,
     *                  D/K Selisih Persediaan.
     *
     * Petugas gudang tak pernah memilih akun: akun lawannya sudah ditetapkan
     * bagian keuangan di master. Dulu layar ini mengubah stok TANPA jurnal sama
     * sekali, sehingga nilai persediaan menjauh dari buku besar tanpa gejala.
     */
    public function mutasi(Request $request, Inventory $inventory): RedirectResponse
    {
        $data = $request->validate([
            'jenis' => ['required', Rule::in(['pemakaian', 'opname'])],
            'tanggal' => ['required', 'date'],
            'jumlah' => ['required_if:jenis,pemakaian', 'nullable', 'numeric', 'gt:0'],
            'stok_fisik' => ['required_if:jenis,opname', 'nullable', 'numeric', 'min:0'],
            // Bagian yang memakai barangnya — dipilih di sini, bukan di master:
            // barang yang sama bisa dipakai bagian mana saja.
            'kode_bagian' => ['required', 'string', 'exists:bagian,kode_bagian'],
            'keterangan' => ['nullable', 'string', 'max:255'],
        ]);

        $service = new PersediaanService;
        $idPengguna = $request->user()->id_pengguna;

        try {
            if ($data['jenis'] === 'pemakaian') {
                $mutasi = $service->pemakaian([...$data, 'kode_persediaan' => $inventory->kode_persediaan], $idPengguna);
                $pesan = "Pemakaian {$mutasi->kuantiti} {$inventory->satuan} {$inventory->nama_persediaan} dicatat "
                    .'(harga pokok Rp '.number_format((float) $mutasi->nilai, 0, ',', '.').').';
            } else {
                $mutasi = $service->opname([...$data, 'kode_persediaan' => $inventory->kode_persediaan], $idPengguna);
                if (! $mutasi) {
                    return back()->with('status', "Opname {$inventory->nama_persediaan}: stok fisik sudah sama dengan catatan, tak ada penyesuaian.");
                }
                $arah = $mutasi->arah === 'masuk' ? 'lebih' : 'kurang';
                $pesan = "Opname {$inventory->nama_persediaan}: stok {$arah} {$mutasi->kuantiti} {$inventory->satuan}, sudah dijurnal.";
            }
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('inventory.index')->with('status', $pesan);
    }

    /** Kartu stok satu barang: seluruh pergerakannya beserta saldo berjalan. */
    public function kartu(Request $request, Inventory $inventory): View
    {
        return view('inventory.kartu', [
            'item' => $inventory,
            'rows' => (new PersediaanService)->kartuStok(
                $inventory->kode_persediaan,
                $request->query('from'),
                $request->query('to'),
            ),
            'lapisan' => LapisanPersediaan::where('kode_persediaan', $inventory->kode_persediaan)
                ->where('kuantiti_sisa', '>', 0)
                ->orderBy('tanggal')->orderBy('id')->get(),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
        ]);
    }

    /**
     * `stok_masuk`/`stok_keluar` kini TURUNAN dari kartu stok — tak bisa lagi
     * diketik, bahkan saat membuat. Stok awal masuk lewat Opname, supaya ia
     * punya baris kartu stok dan jurnalnya sendiri seperti pergerakan lain.
     */
    private function validasi(Request $request, bool $isCreate): array
    {
        return $request->validate([
            'nama_persediaan' => ['required', 'string', 'max:255'],
            'satuan' => ['required', 'string', 'max:50'],
            'harga_perolehan' => ['required', 'numeric', 'min:0'],
            'kode_coa' => ['required', 'string', 'exists:coa_detail,kode_coa'],
            'kode_coa_beban' => ['required', 'string', 'exists:coa_detail,kode_coa'],
            'kode_coa_selisih' => ['required', 'string', 'exists:coa_detail,kode_coa'],
            'status' => ['required', Rule::in(['aktif', 'nonaktif'])],
        ]);
    }

    private function nextKode(): string
    {
        $last = Inventory::where('kode_persediaan', 'like', 'BRG%')->orderByDesc('kode_persediaan')->value('kode_persediaan');
        $n = 1;
        if ($last && is_numeric($tail = substr($last, 3))) {
            $n = (int) $tail + 1;
        }

        return 'BRG'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    private function coaOptions(): array
    {
        return ['' => '— pilih akun —'] + CoaDetail::where('status', 'aktif')->orderBy('kode_coa')->get()
            ->mapWithKeys(fn ($c) => [$c->kode_coa => "{$c->kode_coa} — {$c->nama_coa}"])->all();
    }

    private function bagianOptions(): array
    {
        return ['' => '— pilih bagian —'] + Bagian::orderBy('nama_bagian')
            ->pluck('nama_bagian', 'kode_bagian')->all();
    }
}
