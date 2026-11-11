<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Services\PortalWali\AsistenWali;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Panel BANTUAN untuk staf ERP & Wafi Mart (11 Nov 2026): tombol "?" di pojok setiap layar staf.
 * Semua staf yang login boleh bertanya (keputusan user); saklar & batas harian di layar Asisten Bantuan.
 * Nama layar yang sedang dibuka ikut dikirim (keputusan user) — hanya namanya, bukan isinya.
 */
class AsistenStafController extends Controller
{
    /** Dipanggil saat panel DIBUKA (bukan tiap halaman dimuat) — percakapan hari ini + sisa kuota. */
    public function percakapan(Request $request): JsonResponse
    {
        abort_unless(AsistenWali::aktifStaf(), 404);
        $user = $request->user();

        return response()->json([
            'percakapan' => AsistenWali::percakapanHariIniStaf($user)
                ->flatMap(fn ($p) => [['dari' => 'saya', 'teks' => $p->pertanyaan], ['dari' => 'asisten', 'teks' => $p->jawaban, 'artikel' => []]])->values(),
            'sisa' => AsistenWali::sisaHariIniStaf($user),
        ]);
    }

    public function tanya(Request $request, AsistenWali $asisten): JsonResponse
    {
        $data = $request->validate([
            'pertanyaan' => ['required', 'string', 'max:'.AsistenWali::MAKS_PERTANYAAN],
            'layar' => ['nullable', 'string', 'max:300'],
        ]);

        try {
            return response()->json($asisten->tanyaStaf($request->user(), $data['pertanyaan'], $data['layar'] ?? null));
        } catch (AppException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }
}
