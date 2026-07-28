<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Autentikasi berbasis `username` + `password_hash` (User::getAuthPassword
 * memetakan kolom password ke password_hash). Hanya pengguna berstatus `aktif`
 * yang boleh masuk.
 */
class LoginController extends Controller
{
    public function showLogin(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        // Batasi ke akun aktif: nonaktif tidak boleh masuk sama sekali.
        $ok = Auth::attempt(
            [...$credentials, 'status' => 'aktif'],
            $request->boolean('remember'),
        );

        if (! $ok) {
            throw ValidationException::withMessages([
                'username' => 'Username atau kata sandi salah, atau akun tidak aktif.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
