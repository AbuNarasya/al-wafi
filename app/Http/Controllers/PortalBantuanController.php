<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Services\PortalWali\AsistenWali;
use App\Services\PortalWali\SesiWali;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Menu BANTUAN di portal wali (11 Nov 2026): tanya asisten AI + daftar artikel panduan yang bisa dicari.
 * Hanya terbuka bila asisten dinyalakan staf. Bukan di bawah /wali/data, jadi tertutup dalam mode
 * verifikasi lewat link WA.
 */
class PortalBantuanController extends Controller
{
    public function index(): View
    {
        abort_unless(AsistenWali::aktif(), 404);
        $wali = SesiWali::wali();

        return view('portal.bantuan', [
            'artikel' => DB::table('artikel_bantuan')->where('aktif', true)->where('audiens', 'wali')->orderBy('urutan')->orderBy('id')->get(['id', 'judul', 'isi', 'kata_kunci']),
            // Percakapan hari ini tetap tampil saat halaman dibuka ulang.
            'percakapan' => DB::table('percakapan_asisten')->where('id_wali', $wali->id)->where('audiens', 'wali')
                ->where('created_at', '>=', now('Asia/Jakarta')->startOfDay()->utc())
                ->orderBy('id')->get(['pertanyaan', 'jawaban']),
            'sisa' => AsistenWali::sisaHariIni($wali),
        ]);
    }

    public function tanya(Request $request, AsistenWali $asisten): JsonResponse
    {
        $data = $request->validate(['pertanyaan' => ['required', 'string', 'max:'.AsistenWali::MAKS_PERTANYAAN]]);

        try {
            return response()->json($asisten->tanya(SesiWali::wali(), $data['pertanyaan']));
        } catch (AppException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }
}
