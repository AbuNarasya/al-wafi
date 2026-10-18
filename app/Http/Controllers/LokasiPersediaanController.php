<?php

namespace App\Http\Controllers;

use App\Models\BusinessUnit;
use App\Models\LokasiPersediaan;
use App\Services\Ledger\InventoryMovement;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Master LOKASI PERSEDIAAN — gudang, toko (Koperasi Banin/Banat), transit.
 *
 * Unit bisnis lokasi menentukan unit jurnal pemakaian & opname di sana. Lokasi yang
 * masih menyimpan stok tak bisa dinonaktifkan: stoknya akan terkunci di tempat yang
 * tak bisa lagi dipilih siapa pun.
 */
class LokasiPersediaanController extends Controller
{
    public function index(): View
    {
        $stok = DB::table('lapisan_persediaan')->groupBy('kode_lokasi')
            ->selectRaw('kode_lokasi, COUNT(DISTINCT kode_persediaan) FILTER (WHERE kuantiti_sisa > 0) AS barang')
            ->pluck('barang', 'kode_lokasi');

        return view('lokasi-persediaan.index', [
            // "Dalam Perjalanan" lokasi sistem — tak ditampilkan & tak bisa diubah di sini.
            'rows' => LokasiPersediaan::with('unit')->where('kode', '!=', LokasiPersediaan::TRANSIT)->orderBy('urutan')->orderBy('kode')->get(),
            'jumlahBarang' => $stok,
        ]);
    }

    public function create(): View
    {
        return view('lokasi-persediaan.form', [
            'row' => new LokasiPersediaan(['jenis' => 'toko', 'status' => 'aktif']),
            'unitOptions' => $this->unitOptions(),
            ...$this->opsiAkun(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validasi($request);
        $data['kode'] = $this->nextKode();
        $data['urutan'] = (int) LokasiPersediaan::max('urutan') + 1;
        LokasiPersediaan::create($data);

        return redirect()->route('lokasi_persediaan.index')->with('status', "Lokasi {$data['nama']} ditambahkan.");
    }

    public function edit(string $lokasi): View
    {
        return view('lokasi-persediaan.form', [
            'row' => $this->cari($lokasi),
            'unitOptions' => $this->unitOptions(),
            ...$this->opsiAkun(),
        ]);
    }

    public function update(Request $request, string $lokasi): RedirectResponse
    {
        $row = $this->cari($lokasi);
        $data = $this->validasi($request);

        if ($data['status'] === 'nonaktif' && $row->status === 'aktif') {
            if ($row->kode === LokasiPersediaan::BAWAAN) {
                return back()->withInput()->with('error', "{$row->nama} adalah lokasi bawaan — pembelian & penjualan yang tak menyebut lokasi masuk ke sini, jadi tak bisa dinonaktifkan.");
            }
            $sisa = InventoryMovement::stokPerLokasi();
            foreach ($sisa as $perLokasi) {
                if (Money::gtZero($perLokasi[$row->kode] ?? '0', 4)) {
                    return back()->withInput()->with('error', "{$row->nama} masih menyimpan stok. Pindahkan atau opname-kan dulu sampai kosong sebelum dinonaktifkan.");
                }
            }
        }

        $row->update($data);

        return redirect()->route('lokasi_persediaan.index')->with('status', "Lokasi {$row->nama} diperbarui.");
    }

    public function destroy(string $lokasi): RedirectResponse
    {
        $row = $this->cari($lokasi);
        if ($row->kode === LokasiPersediaan::BAWAAN) {
            return back()->with('error', "{$row->nama} adalah lokasi bawaan dan tidak bisa dihapus.");
        }

        try {
            $row->delete();
        } catch (QueryException) {
            return back()->with('error', "{$row->nama} tidak bisa dihapus karena sudah punya riwayat stok. Nonaktifkan saja.");
        }

        return redirect()->route('lokasi_persediaan.index')->with('status', "Lokasi {$row->nama} dihapus.");
    }

    private function cari(string $kode): LokasiPersediaan
    {
        abort_if($kode === LokasiPersediaan::TRANSIT, 404);

        return LokasiPersediaan::findOrFail($kode);
    }

    private function validasi(Request $request): array
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:100'],
            'jenis' => ['required', Rule::in(array_keys(LokasiPersediaan::JENIS))],
            'kode_unit' => ['nullable', 'string', 'exists:business_units,kode_unit'],
            'status' => ['required', Rule::in(['aktif', 'nonaktif'])],
            // Akun kasir — hanya bermakna untuk lokasi toko; boleh dilengkapi belakangan,
            // shift kasir pertama yang menolak bila belum lengkap.
            'kode_coa_kas' => ['nullable', 'string', 'exists:bank_accounts,kode_coa'],
            'kode_coa_pendapatan' => ['nullable', 'string', 'exists:coa_detail,kode_coa'],
            'kode_coa_hpp' => ['nullable', 'string', 'exists:coa_detail,kode_coa'],
            'kode_coa_selisih_kas' => ['nullable', 'string', 'exists:coa_detail,kode_coa'],
            'kode_bagian' => ['nullable', 'string', 'exists:bagian,kode_bagian'],
        ]);
        foreach (array_keys(LokasiPersediaan::AKUN_KASIR) as $k) {
            $data[$k] = ($data[$k] ?? null) ?: null;
        }

        return $data;
    }

    private function nextKode(): string
    {
        $n = (int) LokasiPersediaan::where('kode', '~', '^L[0-9]+$')
            ->selectRaw('MAX(CAST(SUBSTRING(kode FROM 2) AS INTEGER)) AS n')->value('n');

        return 'L'.str_pad((string) ($n + 1), 3, '0', STR_PAD_LEFT);
    }

    /** Pilihan akun untuk isian kasir toko. */
    private function opsiAkun(): array
    {
        $coa = fn (string $awalan) => ['' => '— pilih akun —'] + \App\Models\CoaDetail::where('status', 'aktif')
            ->where('kode_coa', 'like', $awalan.'%')->orderBy('kode_coa')->get()
            ->mapWithKeys(fn ($c) => [$c->kode_coa => "{$c->kode_coa} — {$c->nama_coa}"])->all();

        return [
            'kasOptions' => ['' => '— pilih kas —'] + \App\Models\BankAccount::where('status', 'aktif')->orderBy('kode_coa')
                ->get()->mapWithKeys(fn ($b) => [$b->kode_coa => "{$b->kode_coa} — {$b->nama_rekening}"])->all(),
            'pendapatanOptions' => $coa('4'),
            'bebanOptions' => $coa('5'),
            'selisihOptions' => $coa(''),
            'bagianOptions' => ['' => '— pilih bagian —'] + \App\Models\Bagian::orderBy('nama_bagian')->pluck('nama_bagian', 'kode_bagian')->all(),
        ];
    }

    private function unitOptions(): array
    {
        return ['' => '— unit bawaan modul —'] + BusinessUnit::where('status', 'aktif')
            ->orderBy('nama_unit')->pluck('nama_unit', 'kode_unit')->all();
    }
}
