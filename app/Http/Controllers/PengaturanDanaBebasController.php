<?php

namespace App\Http\Controllers;

use App\Models\AkunPengurangDanaBebas;
use App\Models\CoaDetail;
use App\Services\Modules\DanaBebasService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Akun pengurang dana bebas dipakai — titipan tabungan santri, dompet wali,
 * kantin pihak ketiga, dan kewajiban lain yang uangnya ada di rekening kita
 * tetapi bukan milik kita.
 *
 * Daftarnya PENGATURAN, bukan daftar yang dipaku di kode: akun titipan jenis
 * baru tinggal dicentang tanpa menunggu perubahan program.
 */
class PengaturanDanaBebasController extends Controller
{
    public function index(): View
    {
        $dipilih = AkunPengurangDanaBebas::pluck('kode_coa')->all();
        $rincian = collect((new DanaBebasService)->hitung()['rincian_pengurang'])->keyBy('kode_coa');

        // Ditawarkan akun KEWAJIBAN lebih dulu — di situlah titipan berada —
        // tetapi akun lain tetap bisa dipilih bila memang perlu.
        $akun = CoaDetail::where('status', 'aktif')->orderBy('kode_coa')->get()
            ->sortBy(fn ($c) => [$c->jenis_saldo === 'kredit' ? 0 : 1, $c->kode_coa])
            ->values();

        return view('pengaturan-dana-bebas.index', [
            'akun' => $akun,
            'dipilih' => $dipilih,
            'rincian' => $rincian,
            'dana' => (new DanaBebasService)->hitung(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kode_coa' => ['nullable', 'array'],
            'kode_coa.*' => ['string', 'exists:coa_detail,kode_coa'],
        ]);

        $pilihan = array_values(array_unique($data['kode_coa'] ?? []));

        AkunPengurangDanaBebas::whereNotIn('kode_coa', $pilihan ?: ['-'])->delete();
        foreach ($pilihan as $kode) {
            AkunPengurangDanaBebas::firstOrCreate(['kode_coa' => $kode]);
        }

        return redirect()->route('pengaturan_dana_bebas.index')
            ->with('status', count($pilihan).' akun ditetapkan sebagai pengurang dana bebas.');
    }
}
