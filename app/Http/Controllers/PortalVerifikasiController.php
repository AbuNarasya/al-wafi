<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\Santri;
use App\Models\VerifikasiWali;
use App\Models\Wali;
use App\Services\PortalWali\LinkVerifikasi;
use App\Services\PortalWali\MasukWali;
use App\Services\PortalWali\PendaftaranPortal;
use App\Services\PortalWali\SesiWali;
use App\Services\Ppsb\Tahap;
use App\Support\Audit\Jejak;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * PORTAL WALI — verifikasi data lewat link dari staf (LinkVerifikasi).
 *
 *   /wali/v/{kode}   buka link → pilih peran → MODE VERIFIKASI (sesi terbatas)
 *   /wali/v          jalur cadangan bagi yang kehilangan link
 *   /wali/verifikasi ringkasan → buktikan email → selesai (= akun portal penuh)
 *
 * Pemeriksaan & pembetulan datanya memakai layar Data Wali & Santri yang sudah
 * ada (PortalDataController) — satu aturan ubah data, satu jejak audit.
 */
class PortalVerifikasiController extends Controller
{
    /** Email yang sedang menunggu kode pembuktian. */
    private const MENUNGGU = 'portal.verifikasi_menunggu';

    public function __construct(private readonly LinkVerifikasi $link) {}

    // ---- Membuka link ----

    public function buka(string $kode): View
    {
        $v = $this->link->kenali($kode);
        if ($halangan = $this->halangan($v)) {
            return view('portal.verifikasi-tolak', ['pesan' => $halangan]);
        }
        $this->link->tandaiDibuka($v);

        return view('portal.verifikasi-buka', ['kode' => $kode, 'wali' => $v->wali, 'sampai' => $v->berlaku_sampai]);
    }

    public function pilihPeran(Request $request, string $kode, SesiWali $sesi): View|RedirectResponse
    {
        $v = $this->link->kenali($kode);
        if ($halangan = $this->halangan($v)) {
            return view('portal.verifikasi-tolak', ['pesan' => $halangan]);
        }
        $data = $request->validate(['peran' => ['required', Rule::in(MasukWali::PERAN)]]);

        $sesi->masukVerifikasi($v->wali, $data['peran'], $v->id, $request);

        return redirect()->route('portal.verifikasi.ringkasan');
    }

    private const TUTUP = 'Verifikasi data keluarga sedang tidak dibuka. Bila ada data yang perlu dibetulkan, silakan masuk Portal Wali atau hubungi bagian kesantrian.';

    /** Pesan untuk link yang tak bisa dipakai, atau null bila bisa. */
    private function halangan(?VerifikasiWali $v): ?string
    {
        return match (true) {
            ! LinkVerifikasi::aktif() => self::TUTUP,
            $v === null => 'Link ini tidak dikenal atau sudah diganti dengan link baru. Silakan hubungi bagian kesantrian.',
            $v->wali?->status !== 'aktif' => 'Data keluarga ini sedang dinonaktifkan. Silakan hubungi bagian kesantrian.',
            $v->selesai_pada !== null => 'Verifikasi data keluarga ini sudah selesai. Silakan masuk Portal Wali dengan email yang sudah diverifikasi.',
            now()->lessThan($v->berlaku_mulai) => 'Link ini baru bisa dibuka mulai '.$v->berlaku_mulai->locale('id')->translatedFormat('j F Y').'.',
            ! $v->bisaDipakai() => 'Masa berlaku link ini sudah habis. Silakan hubungi bagian kesantrian untuk link baru.',
            default => null,
        };
    }

    // ---- Jalur cadangan ----

    public function formCari(): View
    {
        return LinkVerifikasi::aktif() ? view('portal.verifikasi-cari') : view('portal.verifikasi-tolak', ['pesan' => self::TUTUP]);
    }

    public function cari(Request $request): View|RedirectResponse
    {
        if (! LinkVerifikasi::aktif()) {
            return view('portal.verifikasi-tolak', ['pesan' => self::TUTUP]);
        }
        $data = $request->validate([
            'nis' => ['required', 'string', 'max:30'],
            'tanggal_lahir' => ['required', 'date'],
            'hp' => ['required', 'string', 'regex:/^\d{4}$/'],
        ], ['hp.regex' => 'Isi 4 angka terakhir nomor HP.']);

        // Dibatasi per NIS juga, bukan hanya per IP: penebak bisa berganti jaringan.
        $kunci = 'verifikasi-cadangan:'.mb_strtolower(trim($data['nis']));
        if (RateLimiter::tooManyAttempts($kunci, 5)) {
            return back()->withInput()->with('error', 'Terlalu banyak percobaan. Coba lagi dalam '
                .(int) ceil(RateLimiter::availableIn($kunci) / 60).' menit, atau hubungi bagian kesantrian.');
        }
        try {
            $v = $this->link->cariCadangan($data['nis'], $data['tanggal_lahir'], $data['hp']);
        } catch (AppException $e) {
            RateLimiter::hit($kunci, 60 * 60);

            return back()->withInput()->with('error', $e->getMessage());
        }
        RateLimiter::clear($kunci);

        return redirect()->route('portal.verifikasi.buka', LinkVerifikasi::kode($v));
    }

    // ---- Mode verifikasi ----

    private function linkAktif(Request $request): VerifikasiWali
    {
        return VerifikasiWali::findOrFail(SesiWali::modeVerifikasi($request));
    }

    private static function emailPeran(Wali $wali, string $peran): string
    {
        return MasukWali::normalEmail((string) $wali->{"email_{$peran}"});
    }

    public function ringkasan(Request $request): View|RedirectResponse
    {
        if (! SesiWali::modeVerifikasi($request)) {
            return redirect()->route('portal.beranda');
        }
        $wali = SesiWali::wali();
        $peran = (string) SesiWali::peran($request);
        $email = self::emailPeran($wali, $peran);

        return view('portal.verifikasi', [
            'wali' => $wali, 'peran' => $peran, 'link' => $this->linkAktif($request),
            'anak' => Santri::with('jenjang:kode,nama')->where('id_wali', $wali->id)
                ->whereNotIn('status', Tahap::STATUS_FINAL)->orderBy('nama')->get(),
            'email' => $email,
            'emailKembar' => $email !== '' && PendaftaranPortal::emailTerdaftar($email, $wali->id),
            'terbukti' => $email !== '' && $request->session()->get(SesiWali::VERIFIKASI_EMAIL) === $email,
        ]);
    }

    /** Buktikan email yang SUDAH tercatat untuk peran ini (mengganti → lewat Data Wali & Santri). */
    public function kirimKode(Request $request, MasukWali $masuk): RedirectResponse
    {
        $wali = SesiWali::wali();
        $email = self::emailPeran($wali, (string) SesiWali::peran($request));
        if ($email === '') {
            return back()->with('error', 'Email belum diisi. Isi email Anda lebih dulu.');
        }
        if (PendaftaranPortal::emailTerdaftar($email, $wali->id)) {
            return back()->with('error', 'Email ini juga tercatat di keluarga lain, jadi tak bisa dipakai. Ganti dengan email lain.');
        }
        try {
            $kodeUji = $masuk->mintaKode($email, 'verifikasi', $request->ip());
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }
        $request->session()->put(self::MENUNGGU, $email);

        return redirect()->route('portal.verifikasi.kode')->with('kode_uji', $kodeUji);
    }

    public function formKode(Request $request): View|RedirectResponse
    {
        $email = $request->session()->get(self::MENUNGGU);
        if (! $email) {
            return redirect()->route('portal.verifikasi.ringkasan');
        }

        return view('portal.verifikasi-kode', ['tujuan' => $email, 'menit' => MasukWali::MENIT]);
    }

    public function konfirmasiKode(Request $request, MasukWali $masuk): RedirectResponse
    {
        $email = $request->session()->get(self::MENUNGGU);
        if (! $email) {
            return redirect()->route('portal.verifikasi.ringkasan');
        }
        $data = $request->validate(['kode' => ['required', 'string', 'max:10']]);
        if (! $masuk->periksa($email, 'verifikasi', $data['kode'])) {
            return back()->with('error', 'Kode salah atau sudah kedaluwarsa. Periksa lagi, atau minta kode baru.');
        }
        $request->session()->forget(self::MENUNGGU);
        $request->session()->put(SesiWali::VERIFIKASI_EMAIL, $email);

        return redirect()->route('portal.verifikasi.ringkasan')->with('status', 'Email terbukti aktif.');
    }

    public function selesai(Request $request, SesiWali $sesi): RedirectResponse
    {
        $wali = SesiWali::wali();
        $peran = (string) SesiWali::peran($request);
        $email = self::emailPeran($wali, $peran);
        // Email WAJIB (keputusan user): tanpa email terbukti, verifikasi belum selesai.
        if ($email === '' || $request->session()->get(SesiWali::VERIFIKASI_EMAIL) !== $email) {
            return back()->with('error', 'Buktikan dulu email Anda dengan kode sebelum menyelesaikan verifikasi.');
        }
        $data = $request->validate(['setuju' => ['accepted']], ['setuju.accepted' => 'Centang pernyataan bahwa data sudah benar.']);

        $link = $this->linkAktif($request);
        try {
            $this->link->selesai($link, $peran, $email);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }
        try {
            Jejak::catat('verifikasi_data_selesai', [
                'modul' => \App\Services\PortalWali\UbahDataWali::MODUL_JEJAK, 'ref_jenis' => 'Wali', 'ref_id' => $wali->id,
                'detail' => 'Verifikasi data keluarga diselesaikan oleh '.(new \App\Services\PortalWali\UbahDataWali($wali, $peran))->namaPengubah()
                    ." lewat link verifikasi; email {$email} terbukti.",
            ]);
        } catch (\Throwable) {
            // jejak tak boleh menggagalkan penyelesaian
        }
        $sesi->akhiriVerifikasi($request);

        return redirect()->route('portal.beranda')->with('status', 'Terima kasih, verifikasi data selesai. '
            ."Mulai sekarang, masuk Portal Wali dengan email {$email}.");
    }
}
