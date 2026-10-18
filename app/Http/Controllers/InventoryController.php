<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\Bagian;
use App\Models\BarcodePersediaan;
use App\Models\CoaDetail;
use App\Models\Inventory;
use App\Models\KategoriBarang;
use App\Models\LapisanPersediaan;
use App\Models\LokasiPersediaan;
use App\Models\MerkBarang;
use App\Services\Ledger\InventoryMovement;
use App\Services\Modules\PersediaanService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
        $kategori = (string) $request->query('kategori', '');
        $rows = Inventory::query()
            ->with('kategori:kode,nama', 'merk:kode,nama')
            ->when($q !== '', fn ($query) => $query->where(
                // Barcode ikut dicari: petugas toko lebih sering memegang kemasannya
                // daripada mengingat kode BRG.
                fn ($w) => $w->where('kode_persediaan', 'ilike', "%{$q}%")->orWhere('nama_persediaan', 'ilike', "%{$q}%")
                    ->orWhereIn('kode_persediaan', BarcodePersediaan::where('barcode', $q)->select('kode_persediaan')),
            ))
            ->when($kategori !== '', fn ($query) => $query->where('kode_kategori', $kategori))
            ->orderBy('kode_persediaan')->get();

        $lokasi = LokasiPersediaan::pilihan();

        return view('inventory.index', [
            'rows' => $rows,
            'q' => $q,
            'kategori' => $kategori,
            'kategoriOptions' => KategoriBarang::orderBy('nama')->pluck('nama', 'kode')->all(),
            // Kolom per lokasi hanya muncul bila memang ada lebih dari satu lokasi —
            // pesantren yang cuma punya gudang utama tak perlu melihatnya.
            'lokasiOptions' => $lokasi,
            'lokasiNama' => LokasiPersediaan::semuaNama(),
            'stokLokasi' => count($lokasi) > 1 ? InventoryMovement::stokPerLokasi($rows->pluck('kode_persediaan')->all()) : [],
            // Dipakai pemilih "Bagian pemakai" pada formulir Pemakaian/Opname.
            'bagianOptions' => Bagian::orderBy('nama_bagian')->pluck('nama_bagian', 'kode_bagian')->all(),
        ]);
    }

    public function create(): View
    {
        return view('inventory.form', [
            'item' => new Inventory(['status' => 'aktif', 'stok_masuk' => 0, 'stok_keluar' => 0]),
            ...$this->pilihanForm(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        [$data, $satuanLain, $barcode] = $this->validasi($request);
        $data['kode_persediaan'] = $this->nextKode();

        DB::transaction(function () use ($data, $satuanLain, $barcode) {
            $item = Inventory::create($data);
            $this->simpanSatuanDanBarcode($item, $satuanLain, $barcode);
        });

        return redirect()->route('inventory.index')->with('status', "Persediaan {$data['kode_persediaan']} ditambahkan.");
    }

    public function edit(Inventory $inventory): View
    {
        return view('inventory.form', [
            'item' => $inventory->load('satuanLain', 'barcode'),
            ...$this->pilihanForm($inventory),
        ]);
    }

    public function update(Request $request, Inventory $inventory): RedirectResponse
    {
        // Stok TIDAK diubah di sini — ia turunan kartu stok. Pakai Pemakaian/Opname.
        [$data, $satuanLain, $barcode] = $this->validasi($request, $inventory);

        DB::transaction(function () use ($inventory, $data, $satuanLain, $barcode) {
            $inventory->update($data);
            $this->simpanSatuanDanBarcode($inventory, $satuanLain, $barcode);
        });

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
            // Kosong = lokasi bawaan (satu-satunya lokasi bagi yang belum memakai lokasi).
            'kode_lokasi' => ['nullable', 'string', Rule::exists('lokasi_persediaan', 'kode')->where('status', 'aktif')],
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
        $lokasi = $request->query('lokasi') ?: null;

        return view('inventory.kartu', [
            'item' => $inventory->load('satuanLain', 'barcode', 'kategori', 'merk'),
            'rows' => (new PersediaanService)->kartuStok(
                $inventory->kode_persediaan,
                $request->query('from'),
                $request->query('to'),
                $lokasi,
            ),
            'lapisan' => LapisanPersediaan::where('kode_persediaan', $inventory->kode_persediaan)
                ->when($lokasi, fn ($q) => $q->where('kode_lokasi', $lokasi))
                ->where('kuantiti_sisa', '>', 0)
                ->orderBy('tanggal')->orderBy('id')->get(),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'lokasi' => $lokasi,
            // Termasuk lokasi nonaktif & Dalam Perjalanan: riwayatnya tetap boleh dibaca.
            // Penyaringnya hanya muncul bila pesantren memang memakai lebih dari satu lokasi.
            'lokasiOptions' => count(LokasiPersediaan::pilihan()) > 1 ? LokasiPersediaan::semuaNama() : [],
            'stokLokasi' => InventoryMovement::stokPerLokasi([$inventory->kode_persediaan])[$inventory->kode_persediaan] ?? [],
        ]);
    }

    /**
     * `stok_masuk`/`stok_keluar` kini TURUNAN dari kartu stok — tak bisa lagi
     * diketik, bahkan saat membuat. Stok awal masuk lewat Opname, supaya ia
     * punya baris kartu stok dan jurnalnya sendiri seperti pergerakan lain.
     */
    /**
     * @return array{0:array<string,mixed>,1:list<array{satuan:string,isi:string,harga_jual:?string}>,2:list<array{barcode:string,satuan:?string}>}
     */
    private function validasi(Request $request, ?Inventory $item = null): array
    {
        // Baris kosong dari tombol "+ Tambah" yang tak jadi diisi dibuang dulu,
        // supaya tak menjadi galat "wajib diisi" yang membingungkan.
        $request->merge([
            'satuan_lain' => array_values(array_filter((array) $request->input('satuan_lain', []),
                fn ($r) => trim((string) ($r['satuan'] ?? '')) !== '' || trim((string) ($r['isi'] ?? '')) !== '')),
            'barcode' => array_values(array_filter((array) $request->input('barcode', []),
                fn ($r) => trim((string) ($r['barcode'] ?? '')) !== '')),
            'dijual_di_toko' => $request->boolean('dijual_di_toko'),
        ]);

        $data = $request->validate([
            'nama_persediaan' => ['required', 'string', 'max:255'],
            'satuan' => ['required', 'string', 'max:50'],
            'kode_kategori' => ['nullable', 'string', 'exists:kategori_barang,kode'],
            'kode_merk' => ['nullable', 'string', 'exists:merk_barang,kode'],
            'harga_perolehan' => ['required', 'numeric', 'min:0'],
            'dijual_di_toko' => ['boolean'],
            // Barang toko tanpa harga jual tak bisa dipindai kasir — wajib sejak awal.
            'harga_jual' => ['nullable', 'required_if:dijual_di_toko,true', 'numeric', 'min:0'],
            'stok_minimum' => ['nullable', 'numeric', 'min:0'],
            // Promo terjadwal (satuan dasar): harga + tanggal mulai wajib berpasangan.
            'harga_promo' => ['nullable', 'required_with:promo_mulai', 'numeric', 'min:0', 'lt:harga_jual'],
            'promo_mulai' => ['nullable', 'required_with:harga_promo', 'date'],
            'promo_selesai' => ['nullable', 'date', 'after_or_equal:promo_mulai'],
            'kode_coa' => ['required', 'string', 'exists:coa_detail,kode_coa'],
            'kode_coa_beban' => ['required', 'string', 'exists:coa_detail,kode_coa'],
            'kode_coa_selisih' => ['required', 'string', 'exists:coa_detail,kode_coa'],
            'status' => ['required', Rule::in(['aktif', 'nonaktif'])],

            'satuan_lain' => ['array'],
            'satuan_lain.*.satuan' => ['required', 'string', 'max:50', 'distinct:ignore_case'],
            'satuan_lain.*.isi' => ['required', 'numeric', 'gt:1'],
            'satuan_lain.*.harga_jual' => ['nullable', 'numeric', 'min:0'],

            'barcode' => ['array'],
            'barcode.*.barcode' => ['required', 'string', 'max:64', 'regex:/^\S+$/', 'distinct'],
            'barcode.*.satuan' => ['nullable', 'string', 'max:50'],
        ], [
            'harga_jual.required_if' => 'Barang yang dijual di toko wajib punya harga jual.',
            'harga_promo.lt' => 'Harga promo harus lebih murah dari harga jual.',
            'harga_promo.required_with' => 'Tanggal mulai promo diisi, tetapi harga promonya kosong.',
            'promo_mulai.required_with' => 'Harga promo diisi, tetapi tanggal mulainya kosong.',
            'satuan_lain.*.isi.gt' => 'Isi satuan konversi harus lebih dari 1 satuan dasar.',
            'satuan_lain.*.satuan.distinct' => 'Satuan konversi tidak boleh kembar.',
            'barcode.*.barcode.distinct' => 'Barcode yang sama ditulis dua kali.',
            'barcode.*.barcode.regex' => 'Barcode tidak boleh berisi spasi.',
        ]);

        $satuanLain = $data['satuan_lain'];
        $barcode = $data['barcode'];
        unset($data['satuan_lain'], $data['barcode']);

        $dasar = mb_strtolower(trim($data['satuan']));
        $dikenal = [$dasar];
        foreach ($satuanLain as $s) {
            if (mb_strtolower(trim($s['satuan'])) === $dasar) {
                throw ValidationException::withMessages(['satuan_lain' => "Satuan konversi \"{$s['satuan']}\" sama dengan satuan dasar."]);
            }
            $dikenal[] = mb_strtolower(trim($s['satuan']));
        }

        foreach ($barcode as $i => $b) {
            $satuan = trim((string) ($b['satuan'] ?? ''));
            if ($satuan !== '' && ! in_array(mb_strtolower($satuan), $dikenal, true)) {
                throw ValidationException::withMessages(["barcode.{$i}.satuan" => "Satuan \"{$satuan}\" pada barcode {$b['barcode']} belum terdaftar di barang ini."]);
            }
            // Barcode satuan dasar disimpan kosong — satu bentuk saja untuk "satuan dasar".
            $barcode[$i]['satuan'] = ($satuan === '' || mb_strtolower($satuan) === $dasar) ? null : $satuan;
        }

        // Barcode unik di SELURUH barang: kasir memindai satu kode, harus satu barang.
        $bentrok = BarcodePersediaan::with('persediaan:kode_persediaan,nama_persediaan')
            ->whereIn('barcode', array_column($barcode, 'barcode'))
            ->when($item, fn ($q) => $q->where('kode_persediaan', '!=', $item->kode_persediaan))
            ->first();
        if ($bentrok) {
            throw ValidationException::withMessages(['barcode' => "Barcode {$bentrok->barcode} sudah dipakai {$bentrok->persediaan->nama_persediaan}."]);
        }

        return [$data, $satuanLain, $barcode];
    }

    /** Ganti seluruh satuan konversi & barcode barang dengan isi formulir. */
    private function simpanSatuanDanBarcode(Inventory $item, array $satuanLain, array $barcode): void
    {
        $item->satuanLain()->delete();
        foreach ($satuanLain as $s) {
            $item->satuanLain()->create([
                'satuan' => trim($s['satuan']),
                'isi' => $s['isi'],
                'harga_jual' => ($s['harga_jual'] ?? '') === '' ? null : $s['harga_jual'],
            ]);
        }

        $item->barcode()->delete();
        foreach ($barcode as $b) {
            $item->barcode()->create(['barcode' => trim($b['barcode']), 'satuan' => $b['satuan']]);
        }
    }

    /**
     * Kategori/merk yang sudah nonaktif tetap ikut bila sedang dipakai barang ini —
     * tanpa itu pemilihnya kosong dan menyimpan ulang diam-diam mencabut kategorinya.
     */
    private function pilihanForm(?Inventory $item = null): array
    {
        $aktifAtau = fn (string $model, ?string $kode) => $model::where(fn ($q) => $q->where('status', 'aktif')
            ->when($kode, fn ($w) => $w->orWhere('kode', $kode)))
            ->orderBy('nama')->pluck('nama', 'kode')->all();

        return [
            'coaOptions' => $this->coaOptions(),
            'kategoriOptions' => ['' => '— tanpa kategori —'] + $aktifAtau(KategoriBarang::class, $item?->kode_kategori),
            'merkOptions' => ['' => '— tanpa merk —'] + $aktifAtau(MerkBarang::class, $item?->kode_merk),
        ];
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
