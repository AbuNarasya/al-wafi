<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\BarcodePersediaan;
use App\Models\CompanySettings;
use App\Models\Inventory;
use App\Models\LokasiPersediaan;
use App\Models\Penjualan;
use App\Models\ShiftKasir;
use App\Services\Ledger\InventoryMovement;
use App\Services\Modules\KasirService;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * LAYAR KASIR (/pos) — layout penuh sendiri, terpisah dari menu ERP.
 *
 * Kasir tanpa shift terbuka melihat layar Buka Shift; dengan shift terbuka, layar
 * berjualan. Pencarian & penyimpanan struk lewat JSON supaya pemindaian beruntun tak
 * memuat ulang halaman. Harga & stok SELALU dihitung ulang di server
 * ([[App\Services\Modules\KasirService]]) — angka di layar hanya pratinjau.
 */
class PosController extends Controller
{
    public function __construct(private readonly KasirService $kasir) {}

    public function index(Request $request): View
    {
        $shift = $this->shiftTerbuka($request);
        if (! $shift) {
            return view('pos.buka', [
                'tokoOptions' => LokasiPersediaan::where('status', 'aktif')->where('jenis', 'toko')
                    ->orderBy('urutan')->orderBy('kode')->get(),
            ]);
        }

        return view('pos.kasir', [
            'shift' => $shift->load('lokasi'),
            'ringkas' => $this->ringkas($shift),
        ]);
    }

    public function bukaShift(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kode_lokasi' => ['required', 'string', 'exists:lokasi_persediaan,kode'],
            'modal_awal' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            $shift = $this->kasir->bukaShift($data['kode_lokasi'], $data['modal_awal'], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('pos.index')->with('status', "Shift {$shift->nomor} dibuka. Selamat bertugas.");
    }

    /**
     * Cari barang untuk keranjang. `barcode` = pindaian (cocok persis, satuannya ikut
     * barcode); `q` = ketikan nama/kode. Hanya barang yang dijual di toko & berharga.
     */
    public function cari(Request $request): JsonResponse
    {
        $shift = $this->shiftTerbuka($request) ?? abort(409, 'Belum ada shift terbuka.');
        $barcode = trim((string) $request->query('barcode', ''));
        $q = trim((string) $request->query('q', ''));

        $satuanBarcode = null;
        if ($barcode !== '') {
            $b = BarcodePersediaan::find($barcode);
            $items = $b ? Inventory::whereKey($b->kode_persediaan)->get() : new EloquentCollection;
            $satuanBarcode = $b?->satuan;
        } elseif (mb_strlen($q) >= 2) {
            $items = Inventory::where(fn ($w) => $w->where('nama_persediaan', 'ilike', "%{$q}%")->orWhere('kode_persediaan', 'ilike', "%{$q}%"))
                ->orderBy('nama_persediaan')->limit(20)->get();
        } else {
            $items = new EloquentCollection;
        }

        $items = $items->filter(fn ($i) => $i->status === 'aktif' && $i->dijual_di_toko && $i->harga_jual !== null)->values();
        $stok = InventoryMovement::stokPerLokasi($items->pluck('kode_persediaan')->all());
        $items->load('satuanLain');

        return response()->json([
            'items' => $items->map(fn ($i) => [
                'kode' => $i->kode_persediaan,
                'nama' => $i->nama_persediaan,
                'stok' => (float) ($stok[$i->kode_persediaan][$shift->kode_lokasi] ?? 0),
                'satuan_dasar' => $i->satuan,
                'satuan_dipilih' => $satuanBarcode ?? $i->satuan,
                'barcode' => $barcode !== '' ? $barcode : null,
                'opsi' => collect([[$i->satuan, '1']])
                    ->merge($i->satuanLain->map(fn ($s) => [$s->satuan, Money::of($s->isi, 4)]))
                    ->map(fn ($s) => ['satuan' => $s[0], 'isi' => (float) $s[1], 'harga' => (float) KasirService::hargaJual($i, $s[0], $s[1])])
                    ->values()->all(),
            ])->all(),
        ]);
    }

    public function jual(Request $request): JsonResponse
    {
        $data = $request->validate([
            'baris' => ['required', 'array', 'min:1'],
            'baris.*.kode_persediaan' => ['required', 'string'],
            'baris.*.satuan' => ['nullable', 'string', 'max:50'],
            'baris.*.barcode' => ['nullable', 'string', 'max:64'],
            'baris.*.kuantiti' => ['required', 'numeric', 'gt:0'],
            'bayar' => ['required', 'numeric', 'min:0'],
        ]);
        $shift = $this->shiftTerbuka($request);
        if (! $shift) {
            return response()->json(['message' => 'Shift sudah ditutup — muat ulang halaman.'], 409);
        }

        // Barcode hanya catatan baris; barang & satuannya sudah ditentukan saat dipindai.
        $baris = array_map(fn ($b) => [
            'kode_persediaan' => $b['kode_persediaan'], 'satuan' => $b['satuan'] ?? null, 'kuantiti' => $b['kuantiti'],
        ], $data['baris']);

        try {
            $jual = $this->kasir->jual($shift->id_shift, $baris, $data['bayar'], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'nomor' => $jual->nomor,
            'total' => (float) $jual->total,
            'bayar' => (float) $jual->bayar,
            'kembalian' => (float) $jual->kembalian,
            'struk' => route('pos.struk', ['penjualan' => $jual->id_penjualan, 'cetak' => 1]),
            'ringkas' => $this->ringkas($shift),
        ]);
    }

    /** Struk 58 mm. Kasir hanya bisa membuka struk shift-nya sendiri; pemegang hak lihat lain (admin) semua. */
    public function struk(Request $request, Penjualan $penjualan): View
    {
        $penjualan->load(['details.persediaan', 'kasir', 'shift.lokasi']);
        abort_unless($penjualan->id_kasir === $request->user()->id_pengguna || $request->user()->is_admin, 403);

        return view('pos.struk', [
            'jual' => $penjualan,
            'perusahaan' => CompanySettings::find(1),
            'cetak' => $request->boolean('cetak'),
        ]);
    }

    public function riwayat(Request $request): View
    {
        $shift = $this->shiftTerbuka($request);

        return view('pos.riwayat', [
            'shift' => $shift?->load('lokasi'),
            'rows' => $shift ? Penjualan::where('id_shift', $shift->id_shift)->orderByDesc('id_penjualan')->paginate(30) : collect(),
        ]);
    }

    private function shiftTerbuka(Request $request): ?ShiftKasir
    {
        return ShiftKasir::where('id_kasir', $request->user()->id_pengguna)->where('status', 'buka')->first();
    }

    /** Angka berjalan shift untuk kepala layar kasir. */
    private function ringkas(ShiftKasir $shift): array
    {
        $jual = Penjualan::where('id_shift', $shift->id_shift)->where('status', 'selesai');

        return ['struk' => (clone $jual)->count(), 'omzet' => (float) (clone $jual)->sum('total')];
    }
}
