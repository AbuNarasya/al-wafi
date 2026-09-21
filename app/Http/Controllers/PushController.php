<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Services\Modules\PushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Langganan push notification milik SENDIRI.
 *
 * SENGAJA tanpa middleware `hakakses`, dengan alasan yang sama seperti
 * ProfilController: memutuskan apakah ponsel sendiri berbunyi bukan kewenangan
 * modul mana pun. Seluruh operasinya lewat `$request->user()`, tak pernah
 * menerima id pengguna dari permintaan — jadi tak ada jalan mendaftarkan atau
 * mematikan perangkat milik orang lain.
 *
 * Menjawab JSON: pemanggilnya JavaScript yang sudah memegang objek langganan
 * dari peramban, bukan formulir yang perlu dialihkan ke halaman lain.
 */
class PushController extends Controller
{
    public function __construct(private readonly PushService $service) {}

    public function langganan(Request $request): JsonResponse
    {
        if (! $this->service->aktif()) {
            return response()->json([
                'ok' => false,
                'pesan' => 'Push notification belum disiapkan di server ini.',
            ], 503);
        }

        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:500'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
        ]);

        try {
            $l = $this->service->simpanLangganan(
                (int) $request->user()->id_pengguna,
                $data,
                $request->userAgent(),
            );
        } catch (AppException $e) {
            return response()->json(['ok' => false, 'pesan' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'perangkat' => $l->perangkat]);
    }

    public function berhenti(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:500']]);

        $this->service->hapusLangganan((int) $request->user()->id_pengguna, $data['endpoint']);

        // Sengaja selalu `ok`, walau barisnya memang sudah tak ada: yang diminta
        // pengguna adalah "berhenti", dan keadaan itu sudah tercapai.
        return response()->json(['ok' => true]);
    }
}
