<?php

namespace App\Http\Controllers;

use App\Services\Midtrans\KlienMidtrans;
use App\Services\Midtrans\PembayaranMidtrans;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Notifikasi HTTP dari Midtrans (Settings → Payment → Notification URL).
 *
 * Tanpa sesi & tanpa CSRF (bootstrap/app.php). Dua lapis: tanda tangan SHA-512
 * menyaring kiriman palsu, lalu status YANG DIPAKAI ditanyakan ulang ke API
 * Midtrans — isi notifikasi sendiri tak pernah dipercaya untuk membukukan.
 * Galat → 500, supaya Midtrans mengirim ulang (pembukuannya idempoten).
 */
class MidtransNotifikasiController extends Controller
{
    public function __invoke(Request $request, PembayaranMidtrans $midtrans): JsonResponse
    {
        $n = $request->all();
        if (! KlienMidtrans::tandaTanganSah($n)) {
            Log::warning('Notifikasi Midtrans ditolak: tanda tangan tidak sah', ['order_id' => $n['order_id'] ?? null, 'ip' => $request->ip()]);

            return response()->json(['pesan' => 'Tanda tangan tidak sah.'], 403);
        }

        try {
            $t = $midtrans->sinkron((string) $n['order_id']);
        } catch (\Throwable $e) {
            Log::error('Notifikasi Midtrans gagal diproses', ['order_id' => $n['order_id'], 'pesan' => $e->getMessage()]);

            return response()->json(['pesan' => 'Gagal diproses, kirim ulang.'], 500);
        }

        return response()->json(['ok' => true, 'status' => $t?->status]);
    }
}
