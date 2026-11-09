<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\DompetSantri;
use App\Services\Modules\KartuSantriService;
use App\Support\BelanjaKantin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Pengaturan belanja kartu di kantin mitra Wafi Mart (hak `belanja-kantin`, 9 Nov 2026): mode batas harian
 * gabung/pisah, batas kantin bawaan, dan saklar pilihan kantin di portal wali. Lihat App\Support\BelanjaKantin.
 */
class BelanjaKantinController extends Controller
{
    public function edit(): View
    {
        $p = BelanjaKantin::semua();

        return view('belanja-kantin.edit', [
            'p' => $p,
            'batasMartBawaan' => KartuSantriService::batasBawaan(),
            'dimatikanWali' => DompetSantri::where('kantin_nonaktif', true)
                ->whereIn('id_santri', DB::table('santri')->where('status', 'aktif')->select('id'))->count(),
            'diubah' => $p['diubah_oleh'] ? [
                'oleh' => DB::table('users')->where('id_pengguna', $p['diubah_oleh'])->value('nama'),
                'pada' => $p['updated_at'],
            ] : null,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'mode_batas' => ['required', 'in:'.implode(',', array_keys(BelanjaKantin::MODE))],
            'batas_kantin_bawaan' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'saklar_wali' => ['nullable', 'boolean'],
        ]);

        try {
            BelanjaKantin::simpan($data['mode_batas'], $data['batas_kantin_bawaan'], (bool) ($data['saklar_wali'] ?? false), $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('belanja_kantin.edit')->with('status', 'Pengaturan belanja kantin disimpan.');
    }
}
