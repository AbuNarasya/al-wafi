<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\Santri;
use App\Models\VerifikasiWali;
use App\Models\Wali;
use App\Services\PortalWali\KataSandiWali;
use App\Services\PortalWali\LinkVerifikasi;
use App\Services\PortalWali\MasukWali;
use App\Services\PortalWali\SesiWali;
use App\Services\PortalWali\UbahDataWali;
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
 *   /wali/verifikasi ① Data Wali → ② Data Santri → ③ Selesai (tanpa kode, tanpa password)
 *
 * Aturan isian & penyimpanannya sama dengan layar Data Wali & Santri
 * (PortalDataController + UbahDataWali) — satu aturan ubah data, satu jejak audit.
 */
class PortalVerifikasiController extends Controller
{
    public function __construct(private readonly LinkVerifikasi $link) {}

    // ---- Membuka link ----

    public function buka(string $kode): View
    {
        $v = $this->link->kenali($kode);
        if ($halangan = $this->halangan($v)) {
            return view('portal.verifikasi-tolak', ['pesan' => $halangan]);
        }
        $this->link->tandaiDibuka($v);

        return view('portal.verifikasi-buka', ['kode' => $kode, 'wali' => $v->wali, 'sampai' => $v->berlaku_sampai,
            'butuhNis' => LinkVerifikasi::butuhNis($v->wali)]);
    }

    public function pilihPeran(Request $request, string $kode, SesiWali $sesi): View|RedirectResponse
    {
        $v = $this->link->kenali($kode);
        if ($halangan = $this->halangan($v)) {
            return view('portal.verifikasi-tolak', ['pesan' => $halangan]);
        }
        $butuhNis = LinkVerifikasi::butuhNis($v->wali);
        $data = $request->validate([
            'peran' => ['required', Rule::in(MasukWali::PERAN)],
            'nis' => [$butuhNis ? 'required' : 'nullable', 'string', 'max:30'],
        ], ['nis.required' => __('Isi NIS salah satu anak Anda.')]);

        // Langkah kedua: NIS salah satu anak. Dibatasi per LINK — link yang bocor tak bisa
        // dipakai menebak NIS berulang-ulang.
        if ($butuhNis) {
            $kunci = 'verifikasi-nis:'.$v->id;
            if (RateLimiter::tooManyAttempts($kunci, 5)) {
                return back()->withInput()->with('error', __('Terlalu banyak percobaan. Coba lagi dalam :menit menit, atau hubungi bagian kesantrian.',
                    ['menit' => (int) ceil(RateLimiter::availableIn($kunci) / 60)]));
            }
            if (! LinkVerifikasi::nisCocok($v->wali, (string) $data['nis'])) {
                RateLimiter::hit($kunci, 60 * 60);

                return back()->withInput()->with('error', __('NIS tidak cocok dengan anak di keluarga ini. Periksa lagi NIS di rapor atau kartu santri.'));
            }
            RateLimiter::clear($kunci);
        }

        $sesi->masukVerifikasi($v->wali, $data['peran'], $v->id, $request);

        return redirect()->route('portal.verifikasi.ringkasan');
    }

    private const TUTUP = 'Verifikasi data keluarga sedang tidak dibuka. Bila ada data yang perlu dibetulkan, silakan masuk Portal Wali atau hubungi bagian kesantrian.';

    /** Pesan untuk link yang tak bisa dipakai, atau null bila bisa. */
    private function halangan(?VerifikasiWali $v): ?string
    {
        return match (true) {
            ! LinkVerifikasi::aktif() => __(self::TUTUP),
            $v === null => __('Link ini tidak dikenal atau sudah diganti dengan link baru. Silakan hubungi bagian kesantrian.'),
            $v->wali?->status !== 'aktif' => __('Data keluarga ini sedang dinonaktifkan. Silakan hubungi bagian kesantrian.'),
            ! \App\Services\PortalWali\AksesPortal::terbuka($v->wali) => \App\Services\PortalWali\AksesPortal::pesan($v->wali),
            $v->selesai_pada !== null => __('Verifikasi data keluarga ini sudah selesai. Silakan masuk Portal Wali dengan email yang sudah diverifikasi.'),
            now()->lessThan($v->berlaku_mulai) => __('Link ini baru bisa dibuka mulai :tanggal.',
                ['tanggal' => $v->berlaku_mulai->locale(app()->getLocale())->translatedFormat('j F Y')]),
            ! $v->bisaDipakai() => __('Masa berlaku link ini sudah habis. Silakan hubungi bagian kesantrian untuk link baru.'),
            default => null,
        };
    }

    // ---- Jalur cadangan ----

    public function formCari(): View
    {
        return LinkVerifikasi::aktif() ? view('portal.verifikasi-cari') : view('portal.verifikasi-tolak', ['pesan' => __(self::TUTUP)]);
    }

    public function cari(Request $request): View|RedirectResponse
    {
        if (! LinkVerifikasi::aktif()) {
            return view('portal.verifikasi-tolak', ['pesan' => __(self::TUTUP)]);
        }
        $data = $request->validate([
            'nis' => ['required', 'string', 'max:30'],
            'tanggal_lahir' => ['required', 'date'],
            'hp' => ['required', 'string', 'regex:/^\d{4}$/'],
        ], ['hp.regex' => __('Isi 4 angka terakhir nomor HP.')]);

        // Dibatasi per NIS juga, bukan hanya per IP: penebak bisa berganti jaringan.
        $kunci = 'verifikasi-cadangan:'.mb_strtolower(trim($data['nis']));
        if (RateLimiter::tooManyAttempts($kunci, 5)) {
            return back()->withInput()->with('error', __('Terlalu banyak percobaan. Coba lagi dalam :menit menit, atau hubungi bagian kesantrian.',
                ['menit' => (int) ceil(RateLimiter::availableIn($kunci) / 60)]));
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

    // ---- Mode verifikasi: ① Data Wali → ② Data Santri → ③ Selesai ----
    //
    // Bertahap (keputusan user 2 Nov 2026). Setiap "Simpan & lanjut" langsung menyimpan, jadi wali
    // yang berhenti di tengah tak kehilangan isiannya. Email diketik TANPA kode — terbukti nanti
    // saat orangnya masuk pertama kali (kode email → buat password → masuk ulang).

    /** Langkah yang sudah disimpan untuk link yang sedang dibuka: [id link, jumlah langkah]. */
    private const LEWAT = 'portal.verifikasi_lewat';

    private function linkAktif(Request $request): VerifikasiWali
    {
        return VerifikasiWali::findOrFail(SesiWali::modeVerifikasi($request));
    }

    private function lewat(Request $request): int
    {
        [$idLink, $n] = $request->session()->get(self::LEWAT, [null, 0]);

        return $idLink === SesiWali::modeVerifikasi($request) ? (int) $n : 0;
    }

    private function tandaiLewat(Request $request, int $langkah): void
    {
        $request->session()->put(self::LEWAT, [SesiWali::modeVerifikasi($request), max($this->lewat($request), $langkah)]);
    }

    private static function emailPeran(Wali $wali, string $peran): string
    {
        return MasukWali::normalEmail((string) $wali->{"email_{$peran}"});
    }

    private static function anakAktif(Wali $wali): \Illuminate\Database\Eloquent\Collection
    {
        return Santri::with('jenjang:kode,nama')->where('id_wali', $wali->id)
            ->whereNotIn('status', Tahap::STATUS_FINAL)->orderBy('nama')->get();
    }

    /** /wali/verifikasi — membuka langkah yang belum disimpan. */
    public function ringkasan(Request $request): RedirectResponse
    {
        if (! SesiWali::modeVerifikasi($request)) {
            return redirect()->route('portal.beranda');
        }

        return redirect()->route(match ($this->lewat($request)) {
            0 => 'portal.verifikasi.wali',
            1 => 'portal.verifikasi.santri',
            default => 'portal.verifikasi.konfirmasi',
        });
    }

    // ① Data Wali

    public function formWali(Request $request): View|RedirectResponse
    {
        if (! SesiWali::modeVerifikasi($request)) {
            return redirect()->route('portal.beranda');
        }
        $wali = SesiWali::wali();

        return view('portal.verifikasi-wali', [
            'wali' => $wali, 'peranSaya' => (string) SesiWali::peran($request), 'link' => $this->linkAktif($request),
            'lewat' => $this->lewat($request),
            'punyaSandi' => collect(MasukWali::PERAN)->mapWithKeys(fn ($p) => [$p => KataSandiWali::punya($wali, $p)])->all(),
        ]);
    }

    public function simpanWali(Request $request): RedirectResponse
    {
        if (! SesiWali::modeVerifikasi($request)) {
            return redirect()->route('portal.beranda');
        }
        $wali = SesiWali::wali();
        $peran = (string) SesiWali::peran($request);

        // Email orang yang sudah punya password tak diterima dari sini (UbahDataWali::isiEmailTanpaKode).
        $aturan = $pesan = $label = [];
        foreach (MasukWali::PERAN as $p) {
            if (KataSandiWali::punya($wali, $p)) {
                continue;
            }
            // Email WAJIB bagi orang yang sedang verifikasi (keputusan user): dipakai masuk portal.
            $aturan["email_{$p}"] = [$p === $peran ? 'required' : 'nullable', 'email', 'max:255'];
            $label["email_{$p}"] = mb_strtolower(__('Email :peran', ['peran' => __(Wali::PERAN[$p] ?? $p)]));
        }
        $pesan = ["email_{$peran}.required" => __('Email Anda wajib diisi — dipakai untuk masuk Portal Wali.'), 'email' => __('Alamat email tidak sah.')];
        $data = PortalDataController::validasiKeluarga($request, $wali, [$aturan, $pesan, $label]);

        $email = [];
        foreach (MasukWali::PERAN as $p) {
            if (array_key_exists("email_{$p}", $data)) {
                $email[$p] = MasukWali::normalEmail((string) $data["email_{$p}"]);
            }
            unset($data["email_{$p}"]);
        }
        // Satu email = satu orang: masuk portal mencari keluarga & peran lewat email.
        $terisi = array_filter($email + collect(MasukWali::PERAN)->mapWithKeys(fn ($p) => [$p => self::emailPeran($wali, $p)])->all());
        foreach (array_count_values($terisi) as $alamat => $jumlah) {
            if ($jumlah > 1) {
                return back()->withInput()->with('error', __('Email :email dipakai untuk lebih dari satu orang. Setiap orang tua / wali memakai email sendiri.', ['email' => $alamat]));
            }
        }

        $ubah = new UbahDataWali($wali, $peran, tanpaKabarStaf: true);
        try {
            $ubah->ubahKeluarga($data);
            foreach ($email as $p => $alamat) {
                $ubah->isiEmailTanpaKode($p, $alamat);
            }
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
        $this->tandaiLewat($request, 1);

        return redirect()->route('portal.verifikasi.santri');
    }

    // ② Data Santri

    public function formSantri(Request $request): View|RedirectResponse
    {
        if (! SesiWali::modeVerifikasi($request)) {
            return redirect()->route('portal.beranda');
        }
        if ($this->lewat($request) < 1) {
            return redirect()->route('portal.verifikasi.wali');
        }

        return view('portal.verifikasi-santri', ['anak' => self::anakAktif(SesiWali::wali()), 'lewat' => $this->lewat($request)]);
    }

    public function simpanSantri(Request $request): RedirectResponse
    {
        if (! SesiWali::modeVerifikasi($request) || $this->lewat($request) < 1) {
            return redirect()->route('portal.verifikasi.ringkasan');
        }
        $wali = SesiWali::wali();
        $bisa = self::anakAktif($wali)->filter(fn (Santri $s) => UbahDataWali::bolehUbahSantri($s));

        $aturan = $pesan = $label = [];
        foreach ($bisa as $s) {
            [$a, $p, $l] = PortalDataController::aturanSantri("santri.{$s->id}.");
            $aturan += $a;
            $pesan += $p;
            // Galat menyebut nama anaknya — banyak anak dalam satu formulir.
            $label += array_map(fn ($x) => $x.' ('.$s->nama.')', $l);
        }
        $data = $request->validate($aturan, $pesan, $label);

        $ubah = new UbahDataWali($wali, (string) SesiWali::peran($request), tanpaKabarStaf: true);
        try {
            foreach ($bisa as $s) {
                $ubah->ubahSantri($s, $data['santri'][$s->id] ?? []);
            }
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
        $this->tandaiLewat($request, 2);

        return redirect()->route('portal.verifikasi.konfirmasi');
    }

    // ③ Selesai

    public function formKonfirmasi(Request $request): View|RedirectResponse
    {
        if (! SesiWali::modeVerifikasi($request)) {
            return redirect()->route('portal.beranda');
        }
        if ($this->lewat($request) < 2) {
            return redirect()->route('portal.verifikasi.ringkasan');
        }

        return view('portal.verifikasi-konfirmasi', ['wali' => SesiWali::wali()->refresh(), 'anak' => self::anakAktif(SesiWali::wali()),
            'lewat' => $this->lewat($request)]);
    }

    public function selesai(Request $request, SesiWali $sesi): RedirectResponse
    {
        if (! SesiWali::modeVerifikasi($request) || $this->lewat($request) < 2) {
            return redirect()->route('portal.verifikasi.ringkasan');
        }
        $wali = SesiWali::wali()->refresh();
        $peran = (string) SesiWali::peran($request);
        $email = self::emailPeran($wali, $peran);
        // Email WAJIB terisi (keputusan user) — dibuktikan saat masuk pertama kali, bukan di sini.
        if ($email === '') {
            return redirect()->route('portal.verifikasi.wali')->with('error', __('Email Anda wajib diisi — dipakai untuk masuk Portal Wali.'));
        }
        $request->validate(['setuju' => ['accepted']], ['setuju.accepted' => __('Centang pernyataan bahwa data sudah benar.')]);

        try {
            $this->link->selesai($this->linkAktif($request), $peran, $email);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }
        try {
            Jejak::catat('verifikasi_data_selesai', [
                'modul' => UbahDataWali::MODUL_JEJAK, 'ref_jenis' => 'Wali', 'ref_id' => $wali->id,
                'detail' => 'Verifikasi data keluarga diselesaikan oleh '.(new UbahDataWali($wali, $peran))->namaPengubah()
                    ." lewat link verifikasi; email {$email} (dibuktikan saat masuk pertama kali).",
            ]);
        } catch (\Throwable) {
            // jejak tak boleh menggagalkan penyelesaian
        }
        // Halaman penutup, BUKAN aplikasi (keputusan user 14 Okt 2026): sesinya diakhiri,
        // ringkasan dibawa sebagai flash.
        $ringkasan = [
            'peran' => Wali::PERAN[$peran] ?? $peran,
            'nama' => $wali->{"nama_{$peran}"} ?: $wali->nama,
            'email' => $email,
            'telepon' => $wali->{"telepon_{$peran}"} ?: $wali->telepon,
            'punya_sandi' => KataSandiWali::punya($wali, $peran),
            'anak' => self::anakAktif($wali)
                ->map(fn (Santri $s) => ['nama' => $s->nama, 'jenjang' => $s->jenjang?->nama ?? $s->kode_jenjang, 'nis' => $s->nis])->all(),
        ];
        $bahasa = app()->getLocale();
        $sesi->keluar($request);
        $request->session()->forget(self::LEWAT);
        // Halaman penutup tetap dalam bahasa pilihan wali (sesi baru sesudah keluar).
        $request->session()->put(\App\Support\BahasaPortal::SESI, $bahasa);

        return redirect()->route('portal.verifikasi.terima_kasih')->with('ringkasan_verifikasi', $ringkasan);
    }

    public function terimaKasih(Request $request): View|RedirectResponse
    {
        $ringkasan = $request->session()->get('ringkasan_verifikasi');

        return $ringkasan ? view('portal.verifikasi-selesai', ['r' => $ringkasan]) : redirect()->route('portal.masuk');
    }
}
