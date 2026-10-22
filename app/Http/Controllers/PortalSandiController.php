<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Services\PortalWali\IngatPerangkat;
use App\Services\PortalWali\KataSandiWali;
use App\Services\PortalWali\SesiWali;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * PORTAL WALI — membuat & mengganti password (KataSandiWali).
 *
 *   /wali/sandi/buat   sesudah masuk dengan kode email (pertama kali / lupa password);
 *                      middleware PortalWali menahan wali di sini sampai selesai.
 *   /wali/sandi/ganti  dari menu Akun: password lama + baru.
 *
 * Password diganti / disetel ulang → perangkat lain ORANG ini dicabut: perangkat
 * yang diingat selamanya tak boleh tetap terbuka bagi pemegang HP lama.
 */
class PortalSandiController extends Controller
{
    public function __construct(private readonly KataSandiWali $sandi, private readonly IngatPerangkat $perangkat) {}

    public function formBuat(Request $request): View|RedirectResponse
    {
        if (! $request->session()->get(SesiWali::WAJIB_SANDI)) {
            return redirect()->route('portal.beranda');
        }
        $wali = SesiWali::wali();

        return view('portal.sandi-buat', ['ganti' => KataSandiWali::punya($wali, (string) SesiWali::peran($request))]);
    }

    public function buat(Request $request): RedirectResponse
    {
        if (! $request->session()->get(SesiWali::WAJIB_SANDI)) {
            return redirect()->route('portal.beranda');
        }
        $data = $request->validate(['sandi' => ['required', 'string', 'max:255'], 'sandi_ulang' => ['required', 'string']],
            ['sandi.required' => __('Isi password baru.'), 'sandi_ulang.required' => __('Ulangi password baru.')]);
        $wali = SesiWali::wali();
        $peran = (string) SesiWali::peran($request);
        // Hanya MENGGANTI password lama (lupa password) yang mengeluarkan perangkat lain —
        // password pertama tak menggantikan apa pun.
        $menggantiLama = KataSandiWali::punya($wali, $peran);

        try {
            $this->sandi->simpan($wali, $peran, $data['sandi'], $data['sandi_ulang']);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }
        if ($menggantiLama) {
            $this->perangkat->cabutLainnya($wali->id, $peran, $request);
        }
        $request->session()->forget(SesiWali::WAJIB_SANDI);
        $request->session()->put(SesiWali::PUNYA_SANDI, true);

        return redirect()->route('portal.beranda')->with('status', __('Password tersimpan. Mulai sekarang masuk dengan email dan password ini.'));
    }

    public function formGanti(): View
    {
        return view('portal.sandi-ganti');
    }

    public function ganti(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sandi_lama' => ['required', 'string'], 'sandi' => ['required', 'string', 'max:255'], 'sandi_ulang' => ['required', 'string'],
        ], ['sandi_lama.required' => __('Isi password lama.'), 'sandi.required' => __('Isi password baru.'), 'sandi_ulang.required' => __('Ulangi password baru.')]);
        $wali = SesiWali::wali();
        $peran = (string) SesiWali::peran($request);

        if (! $this->sandi->cocok($wali, $peran, $data['sandi_lama'])) {
            return back()->with('error', __('Password lama salah. Lupa? Keluar, lalu pakai "Lupa password" di halaman masuk.'));
        }
        try {
            $this->sandi->simpan($wali, $peran, $data['sandi'], $data['sandi_ulang']);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }
        $this->perangkat->cabutLainnya($wali->id, $peran, $request);

        return redirect()->route('portal.akun')->with('status', __('Password diganti. Perangkat lain Anda harus masuk ulang.'));
    }
}
