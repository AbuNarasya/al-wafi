<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\Santri;
use App\Models\Wali;
use App\Services\PortalWali\IngatPerangkat;
use App\Services\PortalWali\MasukWali;
use App\Services\PortalWali\SesiWali;
use App\Services\PortalWali\UbahDataWali;
use App\Services\Ppsb\Tahap;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * PORTAL WALI — menu "Data Wali & Santri": melihat seluruh data pribadi yang
 * tercatat, lalu membetulkannya sendiri. Aturannya di UbahDataWali; kewajiban
 * isian ditegakkan di sini, sama seperti controller portal lainnya.
 *
 * Setiap kueri berangkat dari keluarga yang sedang masuk — id santri di alamat
 * hanya memilih di antara anak keluarga itu (selain itu 404).
 */
class PortalDataController extends Controller
{
    /** Ganti email menunggu kode: [peran sasaran, email baru]. */
    private const EMAIL_BARU = 'portal.email_baru';

    private function ubah(Request $request): UbahDataWali
    {
        return new UbahDataWali(SesiWali::wali(), (string) SesiWali::peran($request),
            tanpaKabarStaf: SesiWali::modeVerifikasi($request) !== null);
    }

    private function anak(int $id): Santri
    {
        return Santri::with(['jenjang:kode,nama', 'jalurPendaftaran:kode,nama'])
            ->where('id_wali', SesiWali::wali()->id)->findOrFail($id);
    }

    // ---- Lihat ----

    public function index(Request $request): View
    {
        $wali = SesiWali::wali();
        $anak = Santri::with(['jenjang:kode,nama', 'jalurPendaftaran:kode,nama'])
            ->where('id_wali', $wali->id)->orderBy('nama')->get();

        return view('portal.data', [
            'tab' => $request->query('tab') === 'wali' ? 'wali' : 'santri',
            'anak' => $anak, 'wali' => $wali, 'peranSaya' => SesiWali::peran($request),
        ]);
    }

    // ---- Data santri ----

    public function formSantri(int $id): View|RedirectResponse
    {
        $santri = $this->anak($id);
        if (! UbahDataWali::bolehUbahSantri($santri)) {
            return redirect()->route('portal.data')->with('error', 'Data santri berstatus '
                .Tahap::labelStatus((string) $santri->status).' tidak bisa diubah lewat portal. Hubungi bagian kesantrian.');
        }

        return view('portal.data-santri', ['santri' => $santri]);
    }

    public function simpanSantri(Request $request, int $id): RedirectResponse
    {
        $santri = $this->anak($id);
        // Nama santri TIDAK diterima dari wali — hanya admin (UbahDataWali::KOLOM_SANTRI_TERKUNCI).
        $data = $request->validate([
            'jenis_kelamin' => ['required', Rule::in(array_keys(UbahDataWali::JENIS_KELAMIN))],
            'tempat_lahir' => ['nullable', 'string', 'max:255'],
            'tanggal_lahir' => ['nullable', 'date', 'before:today'],
            'nisn' => ['nullable', 'string', 'regex:/^[0-9]{1,20}$/'],
            'asal_sekolah' => ['nullable', 'string', 'max:255'],
            'alamat_sekolah_asal' => ['nullable', 'string', 'max:1000'],
            'kepala_sekolah_asal' => ['nullable', 'string', 'max:255'],
            'cp_kepala_sekolah_asal' => ['nullable', 'string', 'max:255'],
            'wali_kelas_asal' => ['nullable', 'string', 'max:255'],
            'cp_wali_kelas_asal' => ['nullable', 'string', 'max:255'],
        ], [
            'required' => ':Attribute wajib diisi.',
            'nisn.regex' => 'NISN hanya berisi angka.',
            'tanggal_lahir.before' => 'Tanggal lahir harus sebelum hari ini.',
        ], UbahDataWali::KOLOM_SANTRI);

        try {
            $perubahan = $this->ubah($request)->ubahSantri($santri, $data);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('portal.data', ['tab' => 'santri'])->with('status', $perubahan === []
            ? 'Tidak ada data yang berubah.'
            : 'Data '.$santri->nama.' tersimpan ('.count($perubahan).' isian diubah). Bagian kesantrian sudah diberi tahu.');
    }

    // ---- Data keluarga ----

    public function formKeluarga(Request $request): View
    {
        return view('portal.data-keluarga', ['wali' => SesiWali::wali(), 'peranSaya' => SesiWali::peran($request)]);
    }

    public function simpanKeluarga(Request $request): RedirectResponse
    {
        $wali = SesiWali::wali();
        $aturan = [
            'nik' => ['nullable', 'string', 'regex:/^[0-9]{16}$/'],
            'alamat' => ['nullable', 'string', 'max:1000'],
        ];
        $label = UbahDataWali::KOLOM_KELUARGA;
        foreach (MasukWali::PERAN as $p) {
            // Kontak utama wajib bernama & ber-HP: nama & telepon keluarga disalin darinya.
            $utama = $p === $wali->kontak_utama;
            $aturan += [
                "nama_{$p}" => [$utama ? 'required' : 'nullable', 'string', 'max:255'],
                "telepon_{$p}" => [$utama ? 'required' : 'nullable', 'string', 'regex:/^[0-9+\-\s()]{8,20}$/'],
                "pekerjaan_{$p}" => ['nullable', 'string', 'max:255'],
                "pekerjaan_lain_{$p}" => ['nullable', "required_if:pekerjaan_{$p},".Wali::PEKERJAAN_LAIN, 'string', 'max:100'],
                "pendapatan_{$p}" => ['nullable', Rule::in(array_keys(Wali::PENDAPATAN))],
            ];
            foreach (['nama', 'telepon', 'pekerjaan', 'pendapatan'] as $j) {
                $label["{$j}_{$p}"] = mb_strtolower(UbahDataWali::labelKeluarga("{$j}_{$p}"));
            }
        }
        $data = $request->validate($aturan, [
            'required' => ':Attribute wajib diisi (kontak utama keluarga).',
            'required_if' => 'Sebutkan pekerjaannya.',
            'nik.regex' => 'NIK berisi 16 angka.',
            'regex' => ':Attribute tidak sah.',
        ], $label);

        // "Lainnya…" menyimpan apa yang diketik wali — sama dengan form pendaftaran.
        foreach (MasukWali::PERAN as $p) {
            if (($data["pekerjaan_{$p}"] ?? null) === Wali::PEKERJAAN_LAIN) {
                $data["pekerjaan_{$p}"] = $data["pekerjaan_lain_{$p}"] ?? null;
            }
            unset($data["pekerjaan_lain_{$p}"]);
        }

        try {
            $perubahan = $this->ubah($request)->ubahKeluarga($data);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('portal.data', ['tab' => 'wali'])->with('status', $perubahan === []
            ? 'Tidak ada data yang berubah.'
            : 'Data keluarga tersimpan ('.count($perubahan).' isian diubah). Bagian kesantrian sudah diberi tahu.');
    }

    // ---- Ganti email (dibuktikan kode ke alamat BARU) ----

    public function formEmail(string $peran): View
    {
        abort_unless(in_array($peran, MasukWali::PERAN, true), 404);
        $wali = SesiWali::wali();

        return view('portal.data-email', [
            'peran' => $peran, 'label' => Wali::PERAN[$peran],
            'nama' => $wali->{"nama_{$peran}"}, 'emailLama' => $wali->{"email_{$peran}"},
        ]);
    }

    public function kirimKodeEmail(Request $request, string $peran, MasukWali $masuk): RedirectResponse
    {
        abort_unless(in_array($peran, MasukWali::PERAN, true), 404);
        $data = $request->validate(['email' => ['required', 'email', 'max:255']], [
            'required' => 'Email baru wajib diisi.', 'email' => 'Alamat email tidak sah.',
        ]);

        try {
            $email = $this->ubah($request)->periksaEmailBaru($peran, $data['email']);
            $kodeUji = $masuk->mintaKode($email, 'ganti_email', $request->ip());
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
        $request->session()->put(self::EMAIL_BARU, [$peran, $email]);

        return redirect()->route('portal.data.email.kode')->with('kode_uji', $kodeUji);
    }

    public function formKodeEmail(Request $request): View|RedirectResponse
    {
        [$peran, $email] = $request->session()->get(self::EMAIL_BARU) + [null, null];
        if (! $email) {
            return redirect()->route('portal.data', ['tab' => 'wali']);
        }

        return view('portal.data-email-kode', [
            'peran' => $peran, 'label' => Wali::PERAN[$peran] ?? $peran,
            'tujuan' => $email, 'menit' => MasukWali::MENIT,
        ]);
    }

    public function konfirmasiEmail(Request $request, MasukWali $masuk, IngatPerangkat $perangkat): RedirectResponse
    {
        [$peran, $email] = $request->session()->get(self::EMAIL_BARU) + [null, null];
        if (! $email) {
            return redirect()->route('portal.data', ['tab' => 'wali']);
        }
        $data = $request->validate(['kode' => ['required', 'string', 'max:10']]);
        if (! $masuk->periksa($email, 'ganti_email', $data['kode'])) {
            return back()->with('error', 'Kode salah atau sudah kedaluwarsa. Periksa lagi, atau minta kode baru.');
        }

        // Perangkat ini diingat? Penggantian email mencabut SEMUA perangkat keluarga
        // (Wali::booted) — yang sedang dipakai, oleh orang yang baru saja membuktikan
        // email barunya, diingat ulang supaya tak ikut terlempar keluar.
        $diingat = $perangkat->kenali($request) !== null;

        try {
            $this->ubah($request)->gantiEmail($peran, $email);
        } catch (AppException $e) {
            $request->session()->forget(self::EMAIL_BARU);

            return redirect()->route('portal.data', ['tab' => 'wali'])->with('error', $e->getMessage());
        }
        $request->session()->forget(self::EMAIL_BARU);
        // Mode verifikasi: email baru milik orang yang sedang masuk baru saja terbukti
        // lewat kode ke alamat itu — langkah "buktikan email" tak perlu diulang.
        if (SesiWali::modeVerifikasi($request) && $peran === SesiWali::peran($request)) {
            $request->session()->put(SesiWali::VERIFIKASI_EMAIL, $email);

            return redirect()->route('portal.verifikasi.ringkasan')->with('status', 'Email diganti dan terbukti aktif.');
        }
        if ($diingat) {
            $perangkat->ingat(SesiWali::wali()->refresh(), (string) SesiWali::peran($request), $request);
        }

        return redirect()->route('portal.data', ['tab' => 'wali'])->with('status',
            'Email '.strtolower(Wali::PERAN[$peran]).' diganti menjadi '.$email.'. Perangkat lain harus masuk ulang dengan kode.');
    }
}
