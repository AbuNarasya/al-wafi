<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * Profil pengguna yang sedang masuk — untuk saat ini hanya penggantian kata
 * sandi sendiri.
 *
 * SENGAJA tanpa middleware `hakakses`: mengganti sandi sendiri bukan kewenangan
 * modul, dan halaman ini tak pernah menyentuh akun orang lain (semua operasinya
 * lewat `$request->user()`, bukan id dari permintaan). Perubahan data akun
 * lainnya (level, bagian, status) tetap hanya lewat modul Pengguna.
 */
class ProfilController extends Controller
{
    public function index(): View
    {
        return view('profil.index', [
            'user' => Auth::user()->loadMissing(['level', 'bagian', 'levelPengajuan']),
        ]);
    }

    public function ubahKataSandi(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'password_lama' => ['required', 'string', 'current_password'],
            'password_baru' => ['required', 'string', 'min:6', 'confirmed', 'different:password_lama'],
        ], [
            'password_lama.required' => 'Kata sandi lama wajib diisi.',
            'password_lama.current_password' => 'Kata sandi lama tidak cocok.',
            'password_baru.required' => 'Kata sandi baru wajib diisi.',
            'password_baru.min' => 'Kata sandi baru minimal 6 karakter.',
            'password_baru.confirmed' => 'Ulangi kata sandi tidak sama dengan kata sandi baru.',
            'password_baru.different' => 'Kata sandi baru harus berbeda dari kata sandi lama.',
        ]);

        $user = $request->user();
        $user->password_hash = Hash::make($data['password_baru']);
        $user->save();

        // Kredensial berubah → id sesi diperbarui, tapi pengguna tetap masuk
        // (regenerate mempertahankan isi sesi).
        $request->session()->regenerate();

        return redirect()->route('profil.index')->with('status', 'Kata sandi berhasil diperbarui.');
    }
}
