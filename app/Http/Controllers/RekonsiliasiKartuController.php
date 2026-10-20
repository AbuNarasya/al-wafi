<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\RekonsiliasiDompetToko as Rekon;
use App\Services\Modules\RekonsiliasiDompetToko;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Rekonsiliasi malam belanja kartu santri (instans Wafi Mart, Fase 3c-2). Hak: rekonsiliasi-kartu
 * lihat / ubah (jalankan ulang & tandai sudah ditangani). Logika: [[RekonsiliasiDompetToko]].
 */
class RekonsiliasiKartuController extends Controller
{
    public function __construct(private readonly RekonsiliasiDompetToko $service) {}

    public function index(): View
    {
        return view('rekonsiliasi-kartu.index', [
            'belumDitangani' => Rekon::where('status', 'perlu_diputus')->whereNull('ditangani_pada')->count(),
            'rows' => Rekon::with('penangan')->orderByDesc('tanggal')->paginate(31),
        ]);
    }

    public function jalankan(Request $request): RedirectResponse
    {
        $data = $request->validate(['tanggal' => ['required', 'date', 'before:today']],
            ['tanggal.before' => 'Rekonsiliasi hanya untuk tanggal yang sudah lewat.']);

        try {
            $r = $this->service->jalankan($data['tanggal']);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with($r->status === 'gagal' ? 'error' : 'status', "Rekonsiliasi {$r->tanggal->format('d/m/Y')}: ".self::label($r->status)
            .($r->pesan ? " — {$r->pesan}" : '.'));
    }

    public function tangani(Request $request, int $rekon): RedirectResponse
    {
        $data = $request->validate(['catatan' => ['required', 'string', 'max:250']]);
        try {
            $this->service->tangani($rekon, $data['catatan'], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Ditandai sudah ditangani.');
    }

    public static function label(string $status): string
    {
        return ['cocok' => 'Cocok', 'dibereskan' => 'Dibereskan otomatis', 'perlu_diputus' => 'Perlu diputus', 'gagal' => 'Gagal (Al Wafi tak terjangkau)'][$status] ?? $status;
    }
}
