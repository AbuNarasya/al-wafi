<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\Mitra;
use App\Models\PenarikanMitra;
use App\Models\PenjualanMitra;
use App\Services\Modules\KasirMitraService;
use App\Services\Modules\MitraKantinService;
use App\Services\Modules\SaldoMitraService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * LAYAR MITRA KANTIN (/mitra) — dibuka mitra dari HP-nya sendiri: kasir kartu, riwayat (batal hari ini),
 * dan laporan penjualan. Hanya akun ber-`id_mitra` (middleware `mitra.saja` + AkunMitra).
 */
class KasirMitraController extends Controller
{
    public function __construct(private readonly KasirMitraService $kasir) {}

    private function mitra(Request $request): Mitra
    {
        return Mitra::findOrFail($request->user()->id_mitra);
    }

    public function kasir(Request $request): View
    {
        $mitra = $this->mitra($request);

        return view('mitra.kasir', [
            'mitra' => $mitra,
            'menu' => $mitra->menu()->where('aktif', true)->get(['id', 'nama', 'harga'])
                ->map(fn ($m) => ['id' => $m->id, 'nama' => $m->nama, 'harga' => (int) $m->harga])->values(),
        ]);
    }

    public function cek(Request $request): JsonResponse
    {
        $d = $request->validate(['uid' => ['required', 'string', 'max:100'], 'total' => ['required', 'numeric', 'min:0']]);

        return $this->json(fn () => $this->kasir->cekKartu($this->mitra($request), $d['uid'], $d['total']));
    }

    public function bayar(Request $request): JsonResponse
    {
        $d = $request->validate([
            'baris' => ['required', 'array', 'max:50'], 'baris.*.id_menu' => ['required', 'integer'], 'baris.*.jumlah' => ['required', 'integer', 'min:1'],
            'uid' => ['required', 'string', 'max:100'], 'pin' => ['nullable', 'string', 'max:12'], 'kunci' => ['nullable', 'string', 'max:64'],
        ]);

        return $this->json(fn () => self::hasil($this->kasir->bayar($this->mitra($request), $request->user()->id_pengguna,
            $d['baris'], $d['uid'], $d['pin'] ?? null, $d['kunci'] ?? null)));
    }

    public function cekLagi(Request $request, int $id): JsonResponse
    {
        return $this->json(fn () => self::hasil($this->kasir->cekLagi($this->mitra($request), $id)));
    }

    public function riwayat(Request $request): View
    {
        $mitra = $this->mitra($request);
        $tanggal = self::tanggal($request->query('tanggal'), now()->toDateString());

        return view('mitra.riwayat', [
            'mitra' => $mitra, 'tanggal' => $tanggal, 'hariIni' => $tanggal === now()->toDateString(),
            'rows' => PenjualanMitra::where('id_mitra', $mitra->id)->whereDate('waktu', $tanggal)
                ->whereIn('status', ['berhasil', 'batal', 'belum_pasti', 'menunggu'])->with('rincian')->orderByDesc('waktu')->get(),
        ]);
    }

    public function batal(Request $request, int $id): RedirectResponse
    {
        $d = $request->validate(['alasan' => ['required', 'string', 'max:255']]);
        try {
            $j = $this->kasir->batal($this->mitra($request), $id, $d['alasan'], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Pembayaran '.$j->nomor.' dibatalkan — Rp '.number_format((float) $j->total, 0, ',', '.').' kembali ke dompet santri.');
    }

    public function laporan(Request $request, MitraKantinService $s): View
    {
        $mitra = $this->mitra($request);
        $dari = self::tanggal($request->query('dari'), now()->startOfMonth()->toDateString());
        $sampai = self::tanggal($request->query('sampai'), now()->toDateString());

        return view('mitra.laporan', ['mitra' => $mitra, 'dari' => $dari, 'sampai' => $sampai, 'lap' => $s->laporan($mitra->id, $dari, $sampai)]);
    }

    // ---- Saldo & penarikan (Paket 3) ----

    public function saldo(Request $request, SaldoMitraService $s): View
    {
        $mitra = $this->mitra($request);

        return view('mitra.saldo', [
            'mitra' => $mitra, 'saldo' => $s->saldo($mitra->id),
            'rows' => PenarikanMitra::where('id_mitra', $mitra->id)->orderByDesc('id')->limit(50)->get(),
        ]);
    }

    public function ajukan(Request $request, SaldoMitraService $s): RedirectResponse
    {
        $d = $request->validate(['nominal' => ['required', 'numeric', 'gt:0', 'max:100000000'], 'catatan' => ['nullable', 'string', 'max:255']]);
        try {
            $p = $s->ajukan($this->mitra($request), $d['nominal'], $d['catatan'] ?? null, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('mitra.saldo')->with('status', "Penarikan {$p->nomor} diajukan. Petugas Wafi Mart akan mentransfer ke rekening Anda; biaya transfer dipotong dari penarikan.");
    }

    public function batalAjuan(Request $request, SaldoMitraService $s, int $id): RedirectResponse
    {
        try {
            $s->batalkan($this->mitra($request), $id);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('mitra.saldo')->with('status', 'Pengajuan penarikan dibatalkan.');
    }

    public function bukti(Request $request, int $id): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $p = PenarikanMitra::where('id_mitra', $this->mitra($request)->id)->findOrFail($id);
        abort_unless($p->bukti_path && \Illuminate\Support\Facades\Storage::disk('local')->exists($p->bukti_path), 404);

        return \App\Support\Unggahan::sajikan('local', $p->bukti_path, $p->bukti_nama);
    }

    public static function hasil(PenjualanMitra $j): array
    {
        return [
            'id' => $j->id, 'nomor' => $j->nomor, 'status' => $j->status, 'total' => Money::of($j->total),
            'nama' => $j->nama_santri, 'sisa_saldo' => $j->sisa_saldo !== null ? Money::of($j->sisa_saldo) : null,
            'waktu' => $j->waktu?->format('d/m/Y H:i'), 'pesan' => $j->status === 'belum_pasti' ? KasirMitraService::PESAN_BELUM_PASTI : $j->pesan,
            'rincian' => $j->rincian->map(fn ($r) => ['nama' => $r->nama, 'jumlah' => $r->jumlah, 'subtotal' => Money::of($r->subtotal)])->all(),
        ];
    }

    private function json(\Closure $f): JsonResponse
    {
        try {
            return response()->json($f());
        } catch (AppException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }

    public static function tanggal(mixed $v, string $bawaan): string
    {
        return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? $v : $bawaan;
    }
}
