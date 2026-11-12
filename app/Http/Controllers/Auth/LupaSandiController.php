<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\AppException;
use App\Http\Controllers\Controller;
use App\Services\Modules\ResetSandiStafService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/** Lupa kata sandi staf: minta tautan ke email → buka tautan → buat sandi baru (lihat ResetSandiStafService). */
class LupaSandiController extends Controller
{
    public function __construct(private readonly ResetSandiStafService $reset) {}

    public function form(): View|RedirectResponse
    {
        return Auth::check() ? redirect()->route('profil.index') : view('auth.lupa-sandi');
    }

    public function kirim(Request $request): RedirectResponse
    {
        $data = $request->validate(['akun' => ['required', 'string', 'max:255']],
            ['akun.required' => 'Isi username atau email Anda.']);

        try {
            $tujuan = $this->reset->minta($data['akun'], $request->ip());
        } catch (AppException $e) {
            return back()->withInput()->withErrors(['akun' => $e->getMessage()]);
        }

        return redirect()->route('sandi_staf.lupa')->with('terkirim', $tujuan);
    }

    public function formReset(string $token): View
    {
        return view('auth.reset-sandi', ['token' => $token, 'baris' => $this->reset->tautanBerlaku($token)]);
    }

    public function simpan(Request $request, string $token): RedirectResponse
    {
        $data = $request->validate([
            'password_baru' => ['required', 'string', 'min:6', 'confirmed'],
        ], [
            'password_baru.required' => 'Kata sandi baru wajib diisi.',
            'password_baru.min' => 'Kata sandi baru minimal 6 karakter.',
            'password_baru.confirmed' => 'Ulangi kata sandi tidak sama dengan kata sandi baru.',
        ]);

        try {
            $user = $this->reset->setel($token, $data['password_baru'], $request->ip());
        } catch (AppException $e) {
            return back()->withErrors(['password_baru' => $e->getMessage()]);
        }

        return redirect()->route('login')->withInput(['username' => $user->username])
            ->with('status', 'Kata sandi baru tersimpan. Silakan masuk dengan kata sandi itu.');
    }
}
