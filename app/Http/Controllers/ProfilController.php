<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Services\Modules\PinPengawasService;
use App\Services\Modules\PushService;
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
            // Kunci PUBLIK VAPID — memang untuk disiarkan ke peramban; tanpa ia,
            // perangkat tak bisa mendaftar ke layanan push. Kosong bila fiturnya
            // belum disiapkan di lingkungan ini, dan tombolnya ikut diam.
            'pushKunci' => (new PushService)->kunciPublik() ?? '',
            'punyaPin' => PinPengawasService::punya(Auth::user()),
        ]);
    }

    /** PIN pengawas kasir milik sendiri — kata sandi akun wajib, supaya sesi yang ditinggal terbuka tak cukup. */
    public function setelPinPengawas(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'password_akun' => ['required', 'string', 'current_password'],
            'pin' => ['required', 'string', 'confirmed'],
        ], [
            'password_akun.current_password' => 'Kata sandi akun tidak cocok.',
            'pin.confirmed' => 'Ulangi PIN tidak sama.',
        ]);

        try {
            (new PinPengawasService)->setel($request->user(), $data['pin']);
        } catch (AppException $e) {
            return back()->withErrors(['pin' => $e->getMessage()]);
        }

        return redirect()->route('profil.index')->with('status', 'PIN pengawas kasir tersimpan.');
    }

    /**
     * Email akun sendiri — tujuan tautan reset kata sandi (12 Nov 2026). Kata sandi akun wajib (sesi yang ditinggal
     * terbuka tak cukup untuk membelokkan pemulihan akun), dan alamat LAMA diberi tahu.
     */
    public function ubahEmail(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'email' => ['nullable', 'email', 'max:255', function ($attr, $nilai, $gagal) use ($user) {
                if (filled($nilai) && \App\Models\User::whereRaw('lower(email) = ?', [mb_strtolower(trim($nilai))])
                    ->where('id_pengguna', '!=', $user->id_pengguna)->exists()) {
                    $gagal('Email ini sudah dipakai akun lain.');
                }
            }],
            'password_akun_email' => ['required', 'string', 'current_password'],
        ], [
            'password_akun_email.required' => 'Isi kata sandi akun Anda.',
            'password_akun_email.current_password' => 'Kata sandi akun tidak cocok.',
            'email.email' => 'Alamat email tidak sah.',
        ]);

        $lama = $user->email;
        $baru = filled($data['email']) ? mb_strtolower(trim($data['email'])) : null;
        if ($baru === ($lama === null ? null : mb_strtolower($lama))) {
            return redirect()->route('profil.index')->with('status', 'Email tidak berubah.');
        }
        $user->update(['email' => $baru]);
        \App\Support\Audit\Jejak::catat('ubah_email_akun', ['modul' => 'user', 'ref_jenis' => 'users', 'ref_id' => $user->id_pengguna,
            'detail' => ['oleh' => 'sendiri', 'lama' => $lama, 'baru' => $baru]]);
        if (filled($lama)) {
            \App\Services\Modules\ResetSandiStafService::kabari($lama, 'Email akun diubah',
                'Email akun Anda di aplikasi ini baru saja diubah menjadi '.($baru ?? '(kosong)').'. Tautan reset kata sandi kini dikirim ke alamat itu.', $user->username);
        }

        return redirect()->route('profil.index')->with('status', 'Email akun tersimpan.');
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
        // Sandi sementara dari admin selesai digantikan sandi buatan sendiri (12 Nov 2026).
        $user->harus_ganti_sandi = false;
        $user->save();

        // Kredensial berubah → id sesi diperbarui, tapi pengguna tetap masuk
        // (regenerate mempertahankan isi sesi).
        $request->session()->regenerate();

        return redirect()->route('profil.index')->with('status', 'Kata sandi berhasil diperbarui.');
    }
}
