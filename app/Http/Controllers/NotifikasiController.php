<?php

namespace App\Http\Controllers;

use App\Services\Modules\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Notifikasi milik pengguna sendiri — TIDAK lewat matriks hak akses: setiap
 * orang hanya pernah melihat barisnya sendiri (disaring id_pengguna), jadi tak
 * ada yang perlu diizinkan per modul. Yang disaring hak akses adalah isi
 * hitungan tugasnya (lihat TugasSaya), bukan loncengnya.
 */
class NotifikasiController extends Controller
{
    public function __construct(private readonly NotificationService $service) {}

    public function index(Request $request): View
    {
        $feed = $this->service->feed($request->user()->id_pengguna);

        return view('notifikasi.index', [
            'tugas' => $feed['tugas'],
            'kabar' => $feed['kabar'],
            'idPpsb' => $this->service->idPembayaranPpsb($feed['tugas']->concat($feed['kabar'])),
        ]);
    }

    public function baca(Request $request, int $id): RedirectResponse
    {
        $this->service->tandaiDibaca($id, $request->user()->id_pengguna);

        return back();
    }

    public function bacaSemua(Request $request): RedirectResponse
    {
        $hasil = $this->service->tandaiSemuaDibaca($request->user()->id_pengguna);

        return back()->with('sukses', "{$hasil['ditandai']} kabar ditandai sudah dibaca.");
    }
}
