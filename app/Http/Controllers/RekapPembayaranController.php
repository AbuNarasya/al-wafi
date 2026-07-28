<?php

namespace App\Http\Controllers;

use App\Models\CompanySettings;
use App\Services\Modules\RekapPembayaranService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Rekap Pembayaran Santri — riwayat seluruh tagihan & pembayaran satu santri
 * (registrasi, uang pangkal, SPP, tagihan lain), plus versi cetak. Murni baca.
 */
class RekapPembayaranController extends Controller
{
    public function __construct(private readonly RekapPembayaranService $service) {}

    /** Pemilih santri (dengan pencarian). */
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $rows = $this->service->opsiSantri($q);

        return view('rekap-pembayaran.index', [
            'rows' => $rows,
            'q' => $q,
            'bayar' => $this->service->ringkasMassal($rows->pluck('id')),
        ]);
    }

    public function show(int $idSantri): View
    {
        return view('rekap-pembayaran.show', $this->service->rekap($idSantri));
    }

    public function cetak(int $idSantri): View
    {
        return view('rekap-pembayaran.print', [
            ...$this->service->rekap($idSantri),
            'company' => CompanySettings::find(1),
        ]);
    }
}
