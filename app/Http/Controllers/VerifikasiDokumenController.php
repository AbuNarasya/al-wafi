<?php

namespace App\Http\Controllers;

use App\Models\PengajuanKeluar;
use App\Services\Modules\BeritaAcaraClearance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * CEK KEASLIAN DOKUMEN — publik, tanpa login (11 Nov 2026). Dibuka dari QR / kode di Berita Acara Clearance.
 * Yang ditampilkan SENGAJA sedikit (nama, NIS, jenjang, nomor, tanggal tahap) — cukup untuk mencocokkan dengan
 * kertasnya, tanpa membuka alasan, catatan, atau keadaan keuangan. Kode salah & nomor tak dikenal pesannya sama.
 */
class VerifikasiDokumenController extends Controller
{
    public function form(): View
    {
        return view('verifikasi-dokumen.cek', ['hasil' => null]);
    }

    public function cari(Request $request): RedirectResponse
    {
        $d = $request->validate(['nomor' => ['required', 'string', 'max:40'], 'kode' => ['required', 'string', 'max:20']]);
        $nomor = preg_replace('/[^A-Za-z0-9\-]/', '', strtoupper(trim($d['nomor'])));
        $kode = preg_replace('/[^A-Za-z0-9]/', '', strtoupper($d['kode']));
        if ($nomor === '' || $kode === '') {
            return back()->withInput()->with('error', 'Nomor dokumen dan kode verifikasi wajib diisi.');
        }

        return redirect()->route('verifikasi_dokumen.clearance', ['nomor' => $nomor, 'kode' => $kode]);
    }

    public function clearance(string $nomor, string $kode): View
    {
        $p = PengajuanKeluar::with(['santri.jenjang'])->where('nomor', $nomor)->first();
        $asli = $p && BeritaAcaraClearance::cocok($p, $kode);

        return view('verifikasi-dokumen.cek', ['hasil' => $asli ? [
            'nomor' => $p->nomor,
            'nama' => $p->santri?->nama,
            'nis' => $p->santri?->nis,
            'jenjang' => $p->santri?->jenjang?->nama ?? $p->santri?->kode_jenjang,
            'status' => $p->labelStatus(),
            'tahap' => collect(BeritaAcaraClearance::TAHAP)->map(fn ($label, $k) => ['label' => $label, 'pada' => $p->{"{$k}_pada"}])->values()->all(),
            'dikeluarkan' => $p->dikeluarkan_pada,
        ] : false]);
    }
}
