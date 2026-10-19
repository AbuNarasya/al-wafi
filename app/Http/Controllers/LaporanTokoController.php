<?php

namespace App\Http\Controllers;

use App\Models\Inventory;
use App\Models\KategoriBarang;
use App\Models\LokasiPersediaan;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * LAPORAN TOKO (Wafi Mart) — hanya membaca: penjualan kasir, retur, dan lapisan stok.
 *
 * Angka penjualan = struk SELESAI dikurangi retur, masing-masing menurut tanggalnya
 * sendiri (retur hari ini mengurangi laporan hari ini, walau struknya dari kemarin) —
 * sama dengan cara jurnal ringkas shift membukukannya, jadi laporan ini cocok dengan
 * akun Pendapatan & HPP toko untuk periode yang sama.
 */
class LaporanTokoController extends Controller
{
    private const TAB = ['barang' => 'Barang Terlaris & Margin', 'kategori' => 'Per Kategori', 'beli' => 'Perlu Dibeli', 'tidak-laku' => 'Tidak Laku'];

    public function index(Request $request): View
    {
        $tab = array_key_exists($request->query('tab'), self::TAB) ? $request->query('tab') : 'barang';
        $dari = $request->query('dari') ?: now()->startOfMonth()->toDateString();
        $sampai = $request->query('sampai') ?: now()->toDateString();
        $lokasi = (string) $request->query('lokasi', '');
        $kategori = (string) $request->query('kategori', '');
        $hari = max(1, min(365, (int) $request->query('hari', 30)));

        $data = match ($tab) {
            'barang' => ['rows' => $this->perBarang($dari, $sampai, $lokasi, $kategori)],
            'kategori' => ['rows' => $this->perKategori($dari, $sampai, $lokasi, $kategori)],
            'beli' => ['rows' => $this->perluDibeli($lokasi, $kategori, $hari)],
            'tidak-laku' => ['rows' => $this->tidakLaku($lokasi, $kategori, $hari)],
        };

        return view('laporan-toko.index', [
            ...$data,
            'tab' => $tab,
            'tabs' => self::TAB,
            'filter' => compact('dari', 'sampai', 'lokasi', 'kategori', 'hari'),
            'lokasiOptions' => LokasiPersediaan::where('jenis', 'toko')->orderBy('urutan')->pluck('nama', 'kode')->all(),
            'kategoriOptions' => KategoriBarang::orderBy('nama')->pluck('nama', 'kode')->all(),
        ]);
    }

    /**
     * Penjualan bersih retur per barang, terlaris di atas.
     *
     * @return Collection<int,object{kode:string,nama:string,kategori:?string,satuan:string,qty:float,omzet:float,hpp:float,laba:float,margin:?float}>
     */
    public function perBarang(string $dari, string $sampai, string $lokasi, string $kategori): Collection
    {
        $jual = DB::table('penjualan_detail as d')->join('penjualan as p', 'p.id_penjualan', '=', 'd.id_penjualan')
            ->where('p.status', 'selesai')
            ->whereDate('p.waktu', '>=', $dari)->whereDate('p.waktu', '<=', $sampai)
            ->when($lokasi !== '', fn ($q) => $q->where('p.kode_lokasi', $lokasi))
            ->groupBy('d.kode_persediaan')
            // Omzet baris memakai porsi diskon struk: total struk / jumlah total barisnya.
            ->selectRaw('d.kode_persediaan, SUM(d.kuantiti) AS qty,
                SUM(d.total * CASE WHEN tb.jumlah > 0 THEN p.total / tb.jumlah ELSE 0 END) AS omzet, SUM(d.hpp) AS hpp')
            ->joinSub(DB::table('penjualan_detail')->groupBy('id_penjualan')->selectRaw('id_penjualan, SUM(total) AS jumlah'),
                'tb', 'tb.id_penjualan', '=', 'p.id_penjualan')
            ->get()->keyBy('kode_persediaan');

        $retur = DB::table('retur_penjualan_detail as d')->join('retur_penjualan as r', 'r.id_retur', '=', 'd.id_retur')
            ->whereDate('r.waktu', '>=', $dari)->whereDate('r.waktu', '<=', $sampai)
            ->when($lokasi !== '', fn ($q) => $q->where('r.kode_lokasi', $lokasi))
            ->groupBy('d.kode_persediaan')
            // HPP yang dibalik hanya barang LAYAK; barang rusak tetap jadi HPP (keputusan user).
            ->selectRaw("d.kode_persediaan, SUM(d.kuantiti) AS qty, SUM(d.nilai) AS nilai,
                SUM(CASE WHEN d.kondisi = 'layak' THEN d.hpp ELSE 0 END) AS hpp")
            ->get()->keyBy('kode_persediaan');

        $kode = $jual->keys()->merge($retur->keys())->unique();
        $barang = Inventory::with('kategori:kode,nama')->whereIn('kode_persediaan', $kode)
            ->when($kategori !== '', fn ($q) => $q->where('kode_kategori', $kategori))->get()->keyBy('kode_persediaan');

        return $barang->map(function ($b) use ($jual, $retur) {
            $j = $jual[$b->kode_persediaan] ?? null;
            $r = $retur[$b->kode_persediaan] ?? null;
            $omzet = round((float) ($j->omzet ?? 0) - (float) ($r->nilai ?? 0), 2);
            $hpp = round((float) ($j->hpp ?? 0) - (float) ($r->hpp ?? 0), 2);

            return (object) [
                'kode' => $b->kode_persediaan, 'nama' => $b->nama_persediaan, 'kategori' => $b->kategori?->nama,
                'satuan' => $b->satuan, 'qty' => (float) ($j->qty ?? 0) - (float) ($r->qty ?? 0),
                'omzet' => $omzet, 'hpp' => $hpp, 'laba' => round($omzet - $hpp, 2),
                'margin' => $omzet > 0 ? round(($omzet - $hpp) / $omzet * 100, 1) : null,
            ];
        })->sortByDesc('omzet')->values();
    }

    /** Ringkasan per kategori dari angka per barang. */
    public function perKategori(string $dari, string $sampai, string $lokasi, string $kategori): Collection
    {
        return $this->perBarang($dari, $sampai, $lokasi, $kategori)
            ->groupBy(fn ($r) => $r->kategori ?? '(tanpa kategori)')
            ->map(function ($g, $nama) {
                $omzet = $g->sum('omzet');
                $hpp = $g->sum('hpp');

                return (object) ['nama' => $nama, 'barang' => $g->count(), 'omzet' => $omzet, 'hpp' => $hpp, 'laba' => $omzet - $hpp,
                    'margin' => $omzet > 0 ? round(($omzet - $hpp) / $omzet * 100, 1) : null];
            })->sortByDesc('omzet')->values();
    }

    /**
     * Barang toko di bawah/pada stok minimum. Saran beli = cukup untuk `$hari` hari ke depan
     * menurut rata-rata laku `$hari` hari terakhir, minimal sampai kembali ke stok minimum.
     */
    public function perluDibeli(string $lokasi, string $kategori, int $hari): Collection
    {
        $stok = DB::table('lapisan_persediaan')->when($lokasi !== '', fn ($q) => $q->where('kode_lokasi', $lokasi))
            ->groupBy('kode_persediaan')->selectRaw('kode_persediaan, SUM(kuantiti_sisa) AS stok')->pluck('stok', 'kode_persediaan');
        $laku = $this->lakuPerBarang($lokasi, now()->subDays($hari)->toDateString());

        return Inventory::with('kategori:kode,nama')->where('status', 'aktif')->where('dijual_di_toko', true)->whereNotNull('stok_minimum')
            ->when($kategori !== '', fn ($q) => $q->where('kode_kategori', $kategori))->get()
            ->map(function ($b) use ($stok, $laku, $hari) {
                $s = (float) ($stok[$b->kode_persediaan] ?? 0);
                // Proyeksi `$hari` ke depan = jumlah laku `$hari` terakhir — dipakai langsung, bukan
                // per_hari × hari: perkalian float bisa jadi 79,0000001 lalu dibulatkan ke 80.
                $lakuTotal = (float) ($laku[$b->kode_persediaan] ?? 0);
                $perHari = $lakuTotal / $hari;

                return (object) [
                    'kode' => $b->kode_persediaan, 'nama' => $b->nama_persediaan, 'kategori' => $b->kategori?->nama, 'satuan' => $b->satuan,
                    'stok' => $s, 'minimum' => (float) $b->stok_minimum, 'per_hari' => round($perHari, 2),
                    'hari_habis' => $perHari > 0 ? (int) floor($s / $perHari) : null,
                    'saran' => max(0, (int) ceil(max($lakuTotal, (float) $b->stok_minimum) - $s)),
                ];
            })
            ->filter(fn ($r) => $r->stok <= $r->minimum)
            ->sortBy(fn ($r) => $r->hari_habis ?? PHP_INT_MAX)->values();
    }

    /** Barang berstok yang tak terjual sejak `$hari` hari, nilai terbesar di atas. */
    public function tidakLaku(string $lokasi, string $kategori, int $hari): Collection
    {
        $stok = DB::table('lapisan_persediaan')->where('kuantiti_sisa', '>', 0)
            ->when($lokasi !== '', fn ($q) => $q->where('kode_lokasi', $lokasi))
            ->groupBy('kode_persediaan')
            ->selectRaw('kode_persediaan, SUM(kuantiti_sisa) AS stok, SUM(kuantiti_sisa * harga_satuan) AS nilai, MIN(tanggal) AS tertua')
            ->get()->keyBy('kode_persediaan');
        $terakhir = DB::table('penjualan_detail as d')->join('penjualan as p', 'p.id_penjualan', '=', 'd.id_penjualan')
            ->where('p.status', 'selesai')->when($lokasi !== '', fn ($q) => $q->where('p.kode_lokasi', $lokasi))
            ->groupBy('d.kode_persediaan')->selectRaw('d.kode_persediaan, MAX(p.waktu) AS terakhir')->pluck('terakhir', 'kode_persediaan');
        $batas = now()->subDays($hari);

        return Inventory::with('kategori:kode,nama')->where('dijual_di_toko', true)->whereIn('kode_persediaan', $stok->keys())
            ->when($kategori !== '', fn ($q) => $q->where('kode_kategori', $kategori))->get()
            ->map(function ($b) use ($stok, $terakhir) {
                $s = $stok[$b->kode_persediaan];
                $akhir = $terakhir[$b->kode_persediaan] ?? null;

                return (object) [
                    'kode' => $b->kode_persediaan, 'nama' => $b->nama_persediaan, 'kategori' => $b->kategori?->nama, 'satuan' => $b->satuan,
                    'stok' => (float) $s->stok, 'nilai' => round((float) $s->nilai, 2),
                    'terakhir' => $akhir ? Carbon::parse($akhir) : null,
                    'umur' => (int) Carbon::parse($s->tertua)->diffInDays(now()->startOfDay()),
                ];
            })
            ->filter(fn ($r) => $r->terakhir === null || $r->terakhir->lt($batas))
            ->sortByDesc('nilai')->values();
    }

    /** @return array<string,float> kuantiti satuan dasar terjual (bersih retur) sejak tanggal itu */
    private function lakuPerBarang(string $lokasi, string $sejak): array
    {
        $jual = DB::table('penjualan_detail as d')->join('penjualan as p', 'p.id_penjualan', '=', 'd.id_penjualan')
            ->where('p.status', 'selesai')->whereDate('p.waktu', '>=', $sejak)
            ->when($lokasi !== '', fn ($q) => $q->where('p.kode_lokasi', $lokasi))
            ->groupBy('d.kode_persediaan')->selectRaw('d.kode_persediaan, SUM(d.kuantiti) AS qty')->pluck('qty', 'kode_persediaan');
        $retur = DB::table('retur_penjualan_detail as d')->join('retur_penjualan as r', 'r.id_retur', '=', 'd.id_retur')
            ->whereDate('r.waktu', '>=', $sejak)->when($lokasi !== '', fn ($q) => $q->where('r.kode_lokasi', $lokasi))
            ->groupBy('d.kode_persediaan')->selectRaw('d.kode_persediaan, SUM(d.kuantiti) AS qty')->pluck('qty', 'kode_persediaan');

        $hasil = [];
        foreach ($jual as $k => $q) {
            $hasil[$k] = (float) $q - (float) ($retur[$k] ?? 0);
        }

        return $hasil;
    }
}
