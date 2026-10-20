<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Modules\LayananDompetToko;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API penghubung dompet santri untuk kasir Wafi Mart (dijaga [[App\Http\Middleware\TandaTanganToko]]).
 * Galat aturan dompet keluar sebagai JSON {message} dengan kode status dari AppException:
 * 404 kartu tak dikenal · 409 butuh PIN / nomor bentrok · 422 saldo kurang · 423 PIN terkunci.
 */
class DompetTokoController extends Controller
{
    public function __construct(private readonly LayananDompetToko $layanan) {}

    public function kartu(Request $request): JsonResponse
    {
        $d = $request->validate(['uid' => ['required', 'string', 'max:100'], 'total' => ['nullable', 'numeric', 'min:0']]);

        return response()->json($this->layanan->cekKartu($d['uid'], $d['total'] ?? 0));
    }

    public function potong(Request $request): JsonResponse
    {
        $d = $request->validate([
            'nomor' => ['required', 'string', 'max:64'], 'uid' => ['required', 'string', 'max:100'],
            'nominal' => ['required', 'numeric', 'gt:0'], 'pin' => ['nullable', 'string', 'max:12'],
            'kode_toko' => ['nullable', 'string', 'max:30'], 'nama_toko' => ['nullable', 'string', 'max:100'],
            'keterangan' => ['nullable', 'string', 'max:255'],
            // Daftar barang untuk portal wali — dibakukan di RincianBelanja::bersihkan().
            'rincian' => ['nullable', 'array'],
        ]);

        return response()->json($this->layanan->potong($d));
    }

    public function kembalikan(Request $request): JsonResponse
    {
        $d = $request->validate([
            'nomor' => ['required', 'string', 'max:64'], 'nomor_asal' => ['required', 'string', 'max:64'],
            'nominal' => ['required', 'numeric', 'gt:0'], 'keterangan' => ['nullable', 'string', 'max:255'],
            'rincian' => ['nullable', 'array'],
        ]);

        return response()->json($this->layanan->kembalikan($d));
    }

    public function transaksi(Request $request): JsonResponse
    {
        $d = $request->validate(['tanggal' => ['required', 'date']]);

        return response()->json(['tanggal' => $d['tanggal'], 'transaksi' => $this->layanan->daftar($d['tanggal'])]);
    }
}
