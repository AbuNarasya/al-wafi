<?php

namespace App\Http\Middleware;

use App\Models\HakAksesModul as HakAksesModulModel;
use App\Support\ModulRegistry;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gerbang hak akses modul untuk rute web. Dipasang setelah `auth`.
 *
 * Pemakaian: ->middleware('hakakses:coa-detail,lihat')
 * Aksi valid: lihat | buat | ubah | hapus (hapus termasuk void/pembatalan).
 *
 * DENY BY DEFAULT dua lapis:
 *   1. Modul tak dikenal (belum terdaftar di registri) → 403.
 *   2. Tidak ada baris hak akses / kolom aksi false → 403.
 *
 * Admin (is_admin) melewati matriks. Akun nonaktif ditolak.
 */
class HakAksesModul
{
    public function handle(Request $request, Closure $next, string $kode, string $aksi = 'lihat'): Response
    {
        if (ModulRegistry::isBebas($kode)) {
            return $next($request);
        }

        $user = Auth::user();
        if (! $user) {
            abort(401, 'Belum masuk.');
        }
        if ($user->status !== 'aktif') {
            abort(403, 'Akun tidak aktif.');
        }

        // Admin lewat: satu-satunya jalan pulang bila matriks salah disetel.
        if ($user->is_admin) {
            return $next($request);
        }

        $modul = ModulRegistry::byKode($kode);
        if (! $modul) {
            abort(403, "Modul \"{$kode}\" belum terdaftar di registri hak akses. Hubungi administrator.");
        }

        $hak = HakAksesModulModel::query()
            ->where('id_pengguna', $user->id_pengguna)
            ->where('kode_modul', $kode)
            ->first();

        if (! $hak || ! $hak->{$aksi}) {
            abort(403, "Anda tidak punya hak \"{$aksi}\" pada modul {$modul['nama']}.");
        }

        return $next($request);
    }
}
