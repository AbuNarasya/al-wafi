<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Modules\AbsensiAsramaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Penerima kiriman ISE — spesifikasi: docs/integrasi-ise-absensi.md. */
class IseController extends Controller
{
    public function absensiAsrama(Request $request, AbsensiAsramaService $svc): JsonResponse
    {
        $data = $request->input('data');
        if (! is_array($data) || $data === [] || ! array_is_list($data)) {
            return response()->json(['message' => '`data` wajib berupa daftar (array) catatan absen.'], 422);
        }
        if (count($data) > AbsensiAsramaService::MAKS_PER_KIRIMAN) {
            return response()->json(['message' => 'Paling banyak '.AbsensiAsramaService::MAKS_PER_KIRIMAN.' catatan per kiriman.'], 413);
        }

        return response()->json($svc->terima($data, $request->ip()));
    }
}
