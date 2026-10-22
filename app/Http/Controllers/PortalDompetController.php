<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\PengajuanTarikTabungan;
use App\Models\Santri;
use App\Services\PortalWali\MasukWali;
use App\Services\PortalWali\PembayaranPortal;
use App\Services\PortalWali\PinTransaksi;
use App\Services\PortalWali\SesiWali;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\View\View;

/**
 * PORTAL WALI — PIN transaksi, pindah saldo, pengajuan tarik tabungan.
 *
 * Membuat PIN pertama & lupa PIN dikonfirmasi KODE EMAIL: sesi yang dipulihkan
 * dari "ingat perangkat" saja tak boleh cukup untuk menyetel PIN yang membuka
 * dompet — siapa pun yang memegang HP itu bisa melakukannya.
 */
class PortalDompetController extends Controller
{
    private const PIN_BARU = 'portal.pin_baru';

    private function portal(Request $request): PembayaranPortal
    {
        return PembayaranPortal::dari(SesiWali::wali(), (string) SesiWali::peran($request));
    }

    private function emailSaya(Request $request): string
    {
        return MasukWali::normalEmail((string) SesiWali::wali()->{'email_'.SesiWali::peran($request)});
    }

    /** Arahkan ke pembuatan PIN bila transaksinya tertolak karena belum punya PIN. */
    public static function tanganiGalat(AppException $e, Request $request): RedirectResponse
    {
        if ($e->status === 428) {
            $asal = parse_url(url()->previous(), PHP_URL_PATH).(($q = parse_url(url()->previous(), PHP_URL_QUERY)) ? "?{$q}" : '');

            return redirect()->route('portal.pin', ['kembali' => $asal])->with('status', __('Buat PIN transaksi dulu untuk mengamankan dompet Anda.'));
        }

        return back()->withInput($request->except('pin'))->with('error', $e->getMessage());
    }

    // ---- PIN ----

    public const PIN_KEMBALI = 'portal.pin_kembali';

    /**
     * Halaman proses yang memicu pembuatan PIN (pindah saldo, bayar, kartu, …) — sesudah PIN jadi, wali
     * dikembalikan ke sana, bukan ke Beranda (permintaan user 21 Okt 2026). Hanya alamat portal sendiri
     * (/wali/…) yang diterima, supaya parameter ini tak bisa dipakai mengarahkan ke situs lain.
     */
    private static function ingatKembali(Request $request, ?string $alamat): void
    {
        // Dibuka tanpa alamat asal (mis. dari menu Akun) → tujuan lama dilupakan, sesudahnya ke Beranda.
        $request->session()->forget(self::PIN_KEMBALI);
        if (is_string($alamat) && preg_match('#^/wali/(?!pin\b)[A-Za-z0-9/_\-?=&%.]*$#', $alamat) && ! str_contains($alamat, '//')) {
            $request->session()->put(self::PIN_KEMBALI, $alamat);
        }
    }

    public function pin(Request $request): View
    {
        self::ingatKembali($request, $request->query('kembali'));
        $pin = $this->portal($request)->pin();

        return view('portal.pin', ['punya' => $pin->punya(), 'terkunci' => $pin->terkunci()]);
    }

    /** Buat pertama / lupa PIN: PIN baru disimpan sementara (terenkripsi), lalu kode dikirim ke email. */
    public function mintaKodePin(Request $request): RedirectResponse
    {
        $data = $request->validate(['pin_baru' => ['required', 'string'], 'pin_ulang' => ['required', 'string']]);
        try {
            PinTransaksi::assertSah($data['pin_baru'], $data['pin_ulang']);
            $kodeUji = $this->masuk()->mintaKode($this->emailSaya($request), 'pin', $request->ip());
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }
        $request->session()->put(self::PIN_BARU, Crypt::encryptString($data['pin_baru']));

        return redirect()->route('portal.pin.konfirmasi')->with('kode_uji', $kodeUji);
    }

    public function formKonfirmasiPin(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has(self::PIN_BARU)) {
            return redirect()->route('portal.pin');
        }

        return view('portal.pin-konfirmasi', ['tujuan' => MasukWali::samarkanEmail($this->emailSaya($request)), 'menit' => MasukWali::MENIT]);
    }

    public function konfirmasiPin(Request $request): RedirectResponse
    {
        $terenkripsi = $request->session()->get(self::PIN_BARU);
        if (! $terenkripsi) {
            return redirect()->route('portal.pin');
        }
        $data = $request->validate(['kode' => ['required', 'string', 'max:10']]);
        if (! $this->masuk()->periksa($this->emailSaya($request), 'pin', $data['kode'])) {
            return back()->with('error', __('Kode salah atau sudah kedaluwarsa.'));
        }

        $this->portal($request)->pin()->simpan(Crypt::decryptString($terenkripsi));
        $request->session()->forget(self::PIN_BARU);

        // Kembali ke halaman proses yang memicunya (mis. pindah saldo pertama kali); tanpa itu ke Beranda.
        $kembali = $request->session()->pull(self::PIN_KEMBALI);

        return ($kembali ? redirect($kembali) : redirect()->route('portal.beranda'))
            ->with('status', __('PIN transaksi berhasil disimpan. Gunakan PIN ini setiap membayar atau memindah saldo.'));
    }

    /** Ganti PIN: cukup PIN lama, tanpa kode email. */
    public function gantiPin(Request $request): RedirectResponse
    {
        $data = $request->validate(['pin_lama' => ['required', 'string'], 'pin_baru' => ['required', 'string'], 'pin_ulang' => ['required', 'string']]);
        $pin = $this->portal($request)->pin();
        try {
            PinTransaksi::assertSah($data['pin_baru'], $data['pin_ulang']);
            $pin->periksa($data['pin_lama']);
            $pin->simpan($data['pin_baru']);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('portal.beranda')->with('status', __('PIN transaksi berhasil diganti.'));
    }

    // ---- Pindah saldo ----

    public function formPindah(Request $request): View|RedirectResponse
    {
        $anak = Santri::with(['dompet', 'tabungan'])->where('id_wali', SesiWali::wali()->id)->where('status', 'aktif')->orderBy('nama')->get();
        if ($anak->isEmpty()) {
            return redirect()->route('portal.beranda')->with('error', __('Pindah saldo tersedia setelah anak Anda berstatus santri aktif.'));
        }

        return view('portal.pindah', [
            'anak' => $anak, 'arah' => PembayaranPortal::ARAH,
            'saldoWali' => \App\Models\DompetWali::where('id_wali', SesiWali::wali()->id)->value('saldo') ?? '0',
        ]);
    }

    public function pindah(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'arah' => ['required', 'string'], 'id_santri' => ['required', 'integer'],
            'id_santri_tujuan' => ['required_if:arah,antar_anak', 'nullable', 'integer'],
            'nominal' => ['required', 'numeric', 'gt:0'], 'pin' => ['nullable', 'string'],
        ]);
        try {
            if ($data['arah'] === 'antar_anak') {
                $this->portal($request)->pindahAntarAnak((int) $data['id_santri'], (int) $data['id_santri_tujuan'],
                    (string) $data['nominal'], $data['pin'] ?? null);
            } else {
                $this->portal($request)->pindah($data['arah'], (int) $data['id_santri'], (string) $data['nominal'], $data['pin'] ?? null);
            }
        } catch (AppException $e) {
            return self::tanganiGalat($e, $request);
        }

        return redirect()->route('portal.dompet')->with('status', __('Saldo dipindahkan: :arah.', [
            'arah' => $data['arah'] === 'antar_anak' ? __('uang saku antar anak') : __(PembayaranPortal::ARAH[$data['arah']][2]),
        ]));
    }

    // ---- Tarik tabungan ----

    public function formTabungan(Request $request): View|RedirectResponse
    {
        $anak = Santri::with('tabungan')->where('id_wali', SesiWali::wali()->id)->where('status', 'aktif')->orderBy('nama')->get();
        if ($anak->isEmpty()) {
            return redirect()->route('portal.beranda')->with('error', __('Tersedia setelah anak Anda berstatus santri aktif.'));
        }

        return view('portal.tarik-tabungan', [
            'anak' => $anak,
            'pengajuan' => PengajuanTarikTabungan::with('santri:id,nama')->where('id_wali', SesiWali::wali()->id)
                ->orderByDesc('id')->limit(20)->get(),
        ]);
    }

    public function ajukanTabungan(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'id_santri' => ['required', 'integer'], 'nominal' => ['required', 'numeric', 'gt:0'],
            'alasan' => ['required', 'string', 'max:500'], 'pin' => ['nullable', 'string'],
        ]);
        try {
            $p = $this->portal($request)->ajukanTarikTabungan((int) $data['id_santri'], (string) $data['nominal'], $data['alasan'], $data['pin'] ?? null);
        } catch (AppException $e) {
            return self::tanganiGalat($e, $request);
        }

        return redirect()->route('portal.dompet.tabungan')->with('status', __('Pengajuan :nomor terkirim. Keuangan akan memutuskannya; Anda menerima kabar di portal.', ['nomor' => $p->nomor]));
    }

    public function batalTabungan(Request $request, int $id): RedirectResponse
    {
        try {
            $this->portal($request)->batalkanPengajuan($id);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('portal.dompet.tabungan')->with('status', __('Pengajuan dibatalkan.'));
    }

    private function masuk(): MasukWali
    {
        return new MasukWali;
    }
}
