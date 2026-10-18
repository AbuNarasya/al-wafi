<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\LokasiPersediaan;
use App\Models\Penjualan;
use App\Models\ShiftKasir;
use App\Services\Modules\KasirService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * PENGAWAS KASIR — rekap harian per toko, rincian shift, dan tiga keputusan yang
 * sengaja TIDAK dipegang kasirnya sendiri: batal struk, tutup shift yang terlupa,
 * terima setoran. Hak: shift-kasir lihat / ubah. Logika: [[KasirService]].
 */
class ShiftKasirController extends Controller
{
    public function __construct(private readonly KasirService $kasir) {}

    public function index(Request $request): View
    {
        $dari = $request->query('dari') ?: now()->subDays(6)->toDateString();
        $sampai = $request->query('sampai') ?: now()->toDateString();
        $lokasi = (string) $request->query('lokasi', '');
        $status = (string) $request->query('status', '');

        // Nama kolom berkualifikasi: kueri rekap di bawah men-join `penjualan`, yang juga punya kode_lokasi.
        $dasar = ShiftKasir::query()
            ->whereDate('shift_kasir.dibuka_pada', '>=', $dari)->whereDate('shift_kasir.dibuka_pada', '<=', $sampai)
            ->when($lokasi !== '', fn ($q) => $q->where('shift_kasir.kode_lokasi', $lokasi));

        // Rekap harian per toko. Omzet shift yang MASIH BUKA dihitung langsung dari struk —
        // angka di kolom shift baru terisi saat ditutup.
        $rekap = (clone $dasar)
            ->leftJoin('penjualan', fn ($j) => $j->on('penjualan.id_shift', '=', 'shift_kasir.id_shift')->where('penjualan.status', 'selesai'))
            ->groupBy(DB::raw('DATE(shift_kasir.dibuka_pada)'), 'shift_kasir.kode_lokasi')
            ->selectRaw('DATE(shift_kasir.dibuka_pada) AS tanggal, shift_kasir.kode_lokasi,
                COUNT(DISTINCT shift_kasir.id_shift) AS shift,
                COUNT(penjualan.id_penjualan) AS struk,
                COALESCE(SUM(penjualan.total), 0) AS omzet,
                COALESCE(SUM(penjualan.total - penjualan.hpp), 0) AS laba_kotor')
            ->orderByDesc('tanggal')->orderBy('shift_kasir.kode_lokasi')->get();
        $selisih = (clone $dasar)->groupBy(DB::raw('DATE(dibuka_pada)'), 'kode_lokasi')
            ->selectRaw("DATE(dibuka_pada) AS tanggal, kode_lokasi, COALESCE(SUM(selisih), 0) AS selisih,
                SUM(CASE WHEN status <> 'diserahkan' THEN 1 ELSE 0 END) AS belum_serah")
            ->get()->keyBy(fn ($r) => $r->tanggal.'|'.$r->kode_lokasi);

        return view('shift-kasir.index', [
            'rekap' => $rekap,
            'selisih' => $selisih,
            'rows' => (clone $dasar)->with(['lokasi', 'kasir'])
                ->when($status !== '', fn ($q) => $q->where('status', $status))
                ->orderByDesc('dibuka_pada')->paginate(30)->withQueryString(),
            'filter' => compact('dari', 'sampai', 'lokasi', 'status'),
            'lokasiOptions' => LokasiPersediaan::where('jenis', 'toko')->orderBy('urutan')->pluck('nama', 'kode')->all(),
            'menunggu' => ShiftKasir::where('status', 'tutup')->count(),
            'terbuka' => ShiftKasir::where('status', 'buka')->whereDate('dibuka_pada', '<', now()->toDateString())->count(),
        ]);
    }

    public function show(ShiftKasir $shift): View
    {
        $shift->load(['lokasi', 'kasir', 'penerima']);

        return view('shift-kasir.show', [
            'shift' => $shift,
            'struk' => Penjualan::with('details.persediaan')->where('id_shift', $shift->id_shift)->orderByDesc('id_penjualan')->get(),
            'retur' => \App\Models\ReturPenjualan::with(['details.persediaan', 'penjualan', 'penyetuju'])->where('id_shift', $shift->id_shift)->orderByDesc('id_retur')->get(),
            'kasOptions' => BankAccount::where('status', 'aktif')->where('kode_coa', '!=', $shift->lokasi->kode_coa_kas)
                ->orderBy('kode_coa')->get()->mapWithKeys(fn ($b) => [$b->kode_coa => "{$b->kode_coa} — {$b->nama_rekening}"])->all(),
        ]);
    }

    /** Tutup shift yang terlupa (kasir pulang tanpa menutup). Uang laci dihitung pengawas. */
    public function tutup(Request $request, ShiftKasir $shift): RedirectResponse
    {
        $data = $request->validate([
            'uang_dihitung' => ['required', 'numeric', 'min:0'],
            'catatan' => ['required', 'string', 'max:255'],
        ], ['catatan.required' => 'Tulis alasan penutupan oleh pengawas (mis. kasir lupa menutup).']);

        try {
            $this->kasir->tutupShift($shift->id_shift, null, $data['uang_dihitung'],
                "Ditutup pengawas: {$data['catatan']}", $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "Shift {$shift->nomor} ditutup dan dijurnal.");
    }

    public function serah(Request $request, ShiftKasir $shift): RedirectResponse
    {
        $data = $request->validate(['kode_coa_kas_tujuan' => ['required', 'string', 'exists:bank_accounts,kode_coa']]);

        try {
            $hasil = $this->kasir->serahTerima($shift->id_shift, $data['kode_coa_kas_tujuan'], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Setoran Rp '.number_format((float) $hasil->setoran, 0, ',', '.').' diterima dan dijurnal.');
    }

    public function batalStruk(Request $request, Penjualan $penjualan): RedirectResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'max:255']]);

        try {
            $this->kasir->batalPenjualan($penjualan->id_penjualan, $data['alasan'], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "Struk {$penjualan->nomor} dibatalkan — stoknya kembali ke toko.");
    }
}
