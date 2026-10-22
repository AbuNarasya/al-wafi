<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Services\Modules\PushService;
use App\Services\PortalWali\PushWaliService;
use App\Services\PortalWali\SesiWali;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Push notification milik ORANG YANG SEDANG MASUK portal (keluarga + peran dari sesi, tak pernah dari permintaan) —
 * langganan per perangkat & kategori pilihannya. Pasangan PushController staf; JS-nya sama (`pushLangganan`).
 */
class PortalPushController extends Controller
{
    public function __construct(private readonly PushWaliService $service, private readonly PushService $push) {}

    public function langganan(Request $request): JsonResponse
    {
        if (! $this->push->aktif()) {
            return response()->json(['ok' => false, 'pesan' => __('Notifikasi belum disiapkan di server ini.')], 503);
        }
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:500'], 'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string'], 'keys.auth' => ['required', 'string'],
        ]);
        try {
            $l = $this->service->simpanLangganan(SesiWali::wali()->id, (string) SesiWali::peran($request), $data, $request->userAgent());
        } catch (AppException $e) {
            return response()->json(['ok' => false, 'pesan' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'perangkat' => $l->perangkat]);
    }

    public function berhenti(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:500']]);
        $this->service->hapusLangganan(SesiWali::wali()->id, (string) SesiWali::peran($request), $data['endpoint']);

        return response()->json(['ok' => true]);
    }

    public function kategori(Request $request): RedirectResponse
    {
        $data = $request->validate(['kategori' => ['nullable', 'array'], 'kategori.*' => ['string', 'max:20']]);
        $this->service->simpanKategori(SesiWali::wali()->id, (string) SesiWali::peran($request), $data['kategori'] ?? []);

        return redirect()->route('portal.akun')->with('status', __('Pilihan notifikasi disimpan.'));
    }
}
