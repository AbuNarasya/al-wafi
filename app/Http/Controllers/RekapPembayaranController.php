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

    /**
     * Pemilih santri (dengan pencarian).
     *
     * DUA lingkup, satu halaman — menunya memang berdiri di dua grup:
     *  • `ppsb`  → hanya yang MASIH punya kewajiban uang pangkal / perlengkapan.
     *              Keduanya dibayar di awal pendaftaran, jadi begitu tertutup,
     *              santrinya bukan lagi pekerjaan PPSB.
     *  • `semua` → seluruh santri (Kependidikan) — riwayatnya tak pernah hilang.
     *
     * @param  'semua'|'ppsb'  $lingkup
     */
    public function index(Request $request, string $lingkup = 'semua'): View
    {
        $q = trim((string) $request->query('q', ''));
        $rows = $this->service->opsiSantri($q, $lingkup);

        return view('rekap-pembayaran.index', [
            'rows' => $rows,
            'q' => $q,
            'lingkup' => $lingkup,
            'rute' => $lingkup === 'ppsb' ? 'rekap_pembayaran.ppsb' : 'rekap_pembayaran.index',
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
