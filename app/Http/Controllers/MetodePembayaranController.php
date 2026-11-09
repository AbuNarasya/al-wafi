<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Services\Midtrans\PembayaranMidtrans;
use App\Support\MetodeBayar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Saklar metode pembayaran portal wali (hak `metode-pembayaran`). Dompet Wali selalu aktif; lainnya bisa
 * dimatikan — Transfer + bukti sebagai cadangan saat payment gateway / VA bermasalah. Lihat App\Support\MetodeBayar.
 */
class MetodePembayaranController extends Controller
{
    public function edit(): View
    {
        $b = DB::table('pengaturan_metode_bayar')->orderBy('id')->first();

        return view('metode-pembayaran.edit', [
            'aktif' => collect(MetodeBayar::METODE)->map(fn ($_, $k) => MetodeBayar::aktif($k))->all(),
            'pengumuman' => $b->pengumuman ?? null,
            'diubah' => $b && $b->diubah_oleh ? [
                'oleh' => DB::table('users')->where('id_pengguna', $b->diubah_oleh)->value('nama'),
                'pada' => $b->updated_at,
            ] : null,
            'midtransSiap' => PembayaranMidtrans::siap(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'aktif' => ['nullable', 'array'],
            'aktif.*' => ['boolean'],
            'pengumuman' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            MetodeBayar::simpan(
                collect(MetodeBayar::METODE)->map(fn ($_, $k) => (bool) ($data['aktif'][$k] ?? false))->all(),
                $data['pengumuman'] ?? null,
                $request->user()->id_pengguna,
            );
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('metode_pembayaran.edit')->with('status', 'Metode pembayaran portal disimpan.');
    }
}
