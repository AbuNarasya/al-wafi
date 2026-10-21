<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\TagihanSantri;
use App\Models\TransaksiMidtrans;
use App\Services\Midtrans\KlienMidtrans;
use App\Services\Midtrans\PembayaranMidtrans;
use App\Services\PortalWali\SesiWali;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * PORTAL WALI — bayar online lewat Midtrans (Virtual Account). Controller tipis →
 * PembayaranMidtrans. Halaman pesanan juga menyinkronkan status ke Midtrans, jadi
 * wali yang kembali dari bank langsung melihat hasilnya walau notifikasinya telat
 * (atau, di laptop pengembang, tak pernah sampai).
 */
class PortalBayarOnlineController extends Controller
{
    public function __construct(private readonly PembayaranMidtrans $midtrans) {}

    /** @return array{id:int,peran:string} */
    private function pencatat(Request $request): array
    {
        return ['id' => SesiWali::wali()->id, 'peran' => (string) SesiWali::peran($request)];
    }

    public function isiDompet(Request $request): RedirectResponse
    {
        $data = $request->validate(['nominal' => ['required', 'integer', 'min:'.PembayaranMidtrans::MIN_ISI_DOMPET]], [
            'nominal.min' => 'Isi saldo online minimal Rp '.number_format(PembayaranMidtrans::MIN_ISI_DOMPET, 0, ',', '.').'.',
        ]);

        try {
            $t = $this->midtrans->mulaiIsiDompet(SesiWali::wali(), $this->pencatat($request), (string) $data['nominal']);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('portal.online.lihat', $t->order_id);
    }

    public function bayarTagihan(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['nominal' => ['required', 'integer', 'min:1']]);
        $tagihan = TagihanSantri::with(['santri', 'jenis'])->find($id);
        if (! $tagihan) {
            return redirect()->route('portal.tagihan')->with('error', 'Tagihan tidak ditemukan.');
        }

        try {
            $t = $this->midtrans->mulaiBayarTagihan(SesiWali::wali(), $this->pencatat($request), $tagihan, (string) $data['nominal']);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('portal.online.lihat', $t->order_id);
    }

    public function lihat(string $orderId): View|RedirectResponse
    {
        $t = $this->milikSaya($orderId);
        if ($t->status === 'menunggu') {
            try {
                $t = $this->midtrans->sinkron($t->order_id) ?? $t;
            } catch (AppException $e) {
                Log::info('Status Midtrans belum bisa diperiksa', ['order_id' => $orderId, 'pesan' => $e->getMessage()]);
            }
        }

        return view('portal.bayar-online', [
            't' => $t->load(['tagihan.santri', 'tagihan.jenis', 'rincian.tagihan.santri', 'rincian.tagihan.jenis']),
            'snapJs' => KlienMidtrans::urlSnapJs(),
            'clientKey' => (string) config('services.midtrans.client_key'),
        ]);
    }

    /**
     * Dipantau halaman pesanan tiap beberapa detik. Biasanya cuma membaca basis data
     * (notifikasi Midtrans yang membukukan); paling sering sekali per 20 detik ia
     * bertanya sendiri ke Midtrans — cadangan bila notifikasinya tak kunjung datang.
     */
    public function status(string $orderId): JsonResponse
    {
        $t = $this->milikSaya($orderId);
        if ($t->status === 'menunggu' && Cache::add('midtrans-sinkron:'.$t->id, 1, 20)) {
            try {
                $t = $this->midtrans->sinkron($t->order_id) ?? $t;
            } catch (AppException) {
                // Dicoba lagi pada putaran berikutnya.
            }
        }

        return response()->json(['status' => $t->status]);
    }

    /**
     * Batalkan pesanan VA. Dengan `ganti=1` ("Ganti metode pembayaran") wali langsung dibawa kembali
     * ke layar bayar — tagihan yang dipilih (`tagihan`, hanya bila memang isi pesanan ini), tagihan
     * pesanan satuan, atau daftar tagihan untuk pesanan gabungan.
     */
    public function batal(Request $request, string $orderId): RedirectResponse
    {
        $t = $this->milikSaya($orderId);
        try {
            $t = $this->midtrans->batalkan($t);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($t->status === 'lunas' || ! $request->boolean('ganti')) {
            return redirect()->route('portal.online.lihat', $t->order_id)->with('status',
                $t->status === 'lunas' ? 'Pembayaran ini ternyata sudah lunas.' : 'Pesanan pembayaran dibatalkan.');
        }

        $isi = $t->jenis === 'bayar_kelompok' ? $t->rincian()->pluck('id_tagihan')->all() : array_filter([$t->id_tagihan]);
        $pilih = (int) $request->input('tagihan');
        $ke = match (true) {
            in_array($pilih, $isi, false) => route('portal.bayar', $pilih),
            $t->jenis === 'bayar_tagihan' => route('portal.bayar', $t->id_tagihan),
            $t->jenis === 'isi_dompet' => route('portal.dompet.isi'),
            default => route('portal.tagihan'),
        };

        return redirect($ke)->with('status', 'Pesanan Virtual Account dibatalkan. Silakan pilih cara bayar lain.');
    }

    private function milikSaya(string $orderId): TransaksiMidtrans
    {
        $t = TransaksiMidtrans::where('order_id', $orderId)->first();
        abort_unless($t && $t->id_wali === SesiWali::wali()->id, 404);

        return $t;
    }
}
