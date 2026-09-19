<?php

namespace App\Http\Controllers;

use App\Models\Jenjang;
use App\Services\Modules\BebasTanggunganService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Bebas Tanggungan (READ-ONLY) — santri yang masih punya tanggungan, DUA ARAH:
 * yang ia hutang (tagihan bersisa) dan yang dititipkan padanya (saldo dompet &
 * tabungan).
 *
 * Gunanya dibereskan jauh sebelum hari kelulusan, bukan pada hari-H saat semua
 * terburu-buru — dan bukan bertahun-tahun kemudian saat angkanya sudah terlalu
 * besar untuk ditelusuri.
 */
class BebasTanggunganController extends Controller
{
    public function index(Request $request): View
    {
        $jenjang = trim((string) $request->query('jenjang', ''));
        $semua = $request->boolean('semua');

        return view('kesantrian.bebas-tanggungan', [
            'rows' => (new BebasTanggunganService)->daftar(
                ['aktif'],
                $jenjang ?: null,
                hanyaBermasalah: ! $semua,
            ),
            'jenjang' => $jenjang,
            'semua' => $semua,
            'jenjangOptions' => Jenjang::orderBy('urutan')->pluck('nama', 'kode')->all(),
        ]);
    }
}
