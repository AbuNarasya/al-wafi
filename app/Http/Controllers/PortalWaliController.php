<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\Santri;
use App\Models\SumberInformasi;
use App\Models\Wali;
use App\Services\PortalWali\KataSandiWali;
use App\Services\PortalWali\MasukWali;
use App\Services\PortalWali\PendaftaranPortal;
use App\Services\PortalWali\SesiWali;
use App\Services\Ppsb\Tahap;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * PORTAL WALI (/wali) — masuk dengan email + kode, pendaftaran mandiri, beranda.
 *
 * Controller tipis: aturan masuk di MasukWali, sesi di SesiWali, pendaftaran di
 * PendaftaranPortal. Kewajiban isian ditegakkan di sini (bukan di service),
 * sama seperti controller staf.
 */
class PortalWaliController extends Controller
{
    /** Urutan tahap pendaftaran baru, untuk bilah progres di beranda. */
    private const TAHAP_PSB = ['calon', 'terbayar', 'terverifikasi', 'diseleksi', 'diterima', 'lolos_kesehatan', 'siap_aktivasi', 'aktif'];

    private const LANGKAH = [
        'calon' => 'Selesaikan pembayaran biaya registrasi. Panitia akan memverifikasinya.',
        'terbayar' => 'Pembayaran registrasi diterima. Panitia sedang memeriksa berkas pendaftaran.',
        'terverifikasi' => 'Berkas lengkap. Menunggu jadwal seleksi dari panitia.',
        'diseleksi' => 'Seleksi sudah dijalani. Menunggu pengumuman hasilnya.',
        'diterima' => 'Alhamdulillah, diterima. Langkah berikutnya pemeriksaan kesehatan.',
        'lolos_kesehatan' => 'Lolos pemeriksaan kesehatan. Menunggu penyelesaian administrasi.',
        'siap_aktivasi' => 'Seluruh tahap selesai. Menjadi santri aktif saat tahun ajaran dimulai.',
    ];

    public function __construct(
        private readonly MasukWali $masuk,
        private readonly SesiWali $sesi,
    ) {}

    // ---- Masuk ----

    /**
     * Halaman depan (permintaan user 15 Okt 2026): dua kartu supaya wali yang belum punya
     * akun langsung tahu harus ke mana — "Masuk Aplikasi" dan "Buat Akun & Daftarkan
     * Calon Santri". Yang sudah masuk langsung ke beranda.
     */
    public function depan(Request $request): View|RedirectResponse
    {
        // Juga alamat awal aplikasi terpasang (manifest-wali.json start_url). Halaman ini di
        // luar middleware portal.wali, jadi perangkat yang diingat dipulihkan DI SINI —
        // kalau tidak, wali yang sudah "ingat perangkat" justru disodori pilihan masuk tiap membuka aplikasi.
        if ((SesiWali::wali() && SesiWali::peran($request)) || $this->sesi->pulihkan($request)) {
            return redirect()->route('portal.beranda');
        }

        return view('portal.depan');
    }

    /** Pendaftaran baru: email → kode → formulir pendaftaran (alur kirimKode yang sama). */
    public function formDaftarBaru(Request $request): View|RedirectResponse
    {
        if (SesiWali::wali() && SesiWali::peran($request)) {
            return redirect()->route('portal.daftar');
        }

        return view('portal.daftar-baru');
    }

    public function formMasuk(Request $request): View|RedirectResponse
    {
        if (SesiWali::wali() && SesiWali::peran($request)) {
            return redirect()->route('portal.beranda');
        }

        return view('portal.masuk');
    }

    /**
     * Kode email — HANYA untuk pertama kali masuk / mendaftar dan lupa password
     * (keputusan user 14 Okt 2026). Keduanya berakhir di layar membuat password.
     */
    public function kirimKode(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255'], 'lupa' => ['nullable', 'boolean']]);

        try {
            $kodeUji = $this->masuk->mintaKode($data['email'], 'masuk', $request->ip());
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
        $request->session()->put(SesiWali::MENUNGGU, MasukWali::normalEmail($data['email']));
        $request->session()->put(SesiWali::LUPA_SANDI, $request->boolean('lupa'));

        return redirect()->route('portal.kode')->with('kode_uji', $kodeUji);
    }

    /** Masuk sehari-hari: email + password. Pesan galatnya tak pernah menyebut email terdaftar atau tidak. */
    public function masukSandi(Request $request, KataSandiWali $sandi): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'], 'sandi' => ['required', 'string', 'max:255'], 'ingat' => ['nullable', 'boolean'],
        ], ['sandi.required' => 'Isi password Anda.']);

        try {
            $hasil = $sandi->masuk($data['email'], $data['sandi'], $request->ip());
        } catch (AppException $e) {
            return back()->withInput($request->except('sandi'))->with('error', $e->getMessage());
        }
        $this->sesi->masuk($hasil['wali'], $hasil['peran'], $request, $request->boolean('ingat'));
        $request->session()->put(SesiWali::PUNYA_SANDI, true);

        return redirect()->route('portal.beranda');
    }

    public function formLupa(): View
    {
        return view('portal.lupa-sandi');
    }

    public function formKode(Request $request): View|RedirectResponse
    {
        $email = $request->session()->get(SesiWali::MENUNGGU);
        if (! $email) {
            return redirect()->route('portal.masuk');
        }

        return view('portal.kode', ['tujuan' => MasukWali::samarkanEmail($email), 'menit' => MasukWali::MENIT]);
    }

    public function periksaKode(Request $request): View|RedirectResponse
    {
        $email = $request->session()->get(SesiWali::MENUNGGU);
        if (! $email) {
            return redirect()->route('portal.masuk');
        }
        $data = $request->validate(['kode' => ['required', 'string', 'max:10'], 'ingat' => ['nullable', 'boolean']]);

        if (! $this->masuk->periksa($email, 'masuk', $data['kode'])) {
            return back()->with('error', 'Kode salah atau sudah kedaluwarsa. Periksa lagi, atau minta kode baru.');
        }

        $keluarga = $this->masuk->cariKeluarga($email);

        return match ($keluarga['status']) {
            'satu' => $this->masukKeluarga($keluarga['wali'], $keluarga['peran'], $request, $request->boolean('ingat')),
            'baru' => $this->mulaiPendaftaran($email, $request),
            'kembar' => view('portal.ditolak', ['pesan' => 'Email ini tercatat di lebih dari satu keluarga, jadi kami tak bisa '
                .'menentukan keluarga yang mana. Silakan hubungi panitia untuk membetulkan data email Anda.']),
            default => view('portal.ditolak', ['pesan' => 'Akun keluarga ini sedang dinonaktifkan. Silakan hubungi panitia.']),
        };
    }

    private function masukKeluarga(Wali $wali, string $peran, Request $request, bool $ingat): RedirectResponse
    {
        $lupa = (bool) $request->session()->pull(SesiWali::LUPA_SANDI);
        $this->sesi->masuk($wali, $peran, $request, $ingat);

        // Kode email cukup sekali: belum punya password, atau lupa password → buat dulu.
        if ($lupa || ! KataSandiWali::punya($wali, $peran)) {
            $request->session()->put(SesiWali::WAJIB_SANDI, true);

            return redirect()->route('portal.sandi.buat');
        }

        return redirect()->route('portal.beranda');
    }

    private function mulaiPendaftaran(string $email, Request $request): RedirectResponse
    {
        $this->sesi->jadikanPendaftar($email, $request);

        return redirect()->route('portal.daftar')->with('status', 'Email terverifikasi. Silakan isi formulir pendaftaran.');
    }

    public function keluar(Request $request): RedirectResponse
    {
        $this->sesi->keluar($request);

        return redirect()->route('portal.masuk')->with('status', 'Anda sudah keluar.');
    }

    // ---- Beranda ----

    public function beranda(Request $request): View
    {
        $wali = SesiWali::wali();
        $peran = SesiWali::peran($request);

        $anak = Santri::with(['jenjang:kode,nama', 'kelas:id,nama', 'tagihan' => fn ($q) => $q->where('perilaku', 'registrasi')
            ->whereNotIn('status', ['batal', 'dihapus'])])
            ->where('id_wali', $wali->id)->orderBy('nama')->get()
            ->map(function (Santri $s) {
                $tahap = array_search($s->status, self::TAHAP_PSB, true);

                return [
                    'id' => $s->id,
                    'nama' => $s->nama, 'jenjang' => $s->jenjang?->nama ?? $s->kode_jenjang, 'tingkat' => $s->tingkat,
                    'kelas' => $s->kelas?->nama,
                    'tahun_ajaran' => $s->tahun_ajaran, 'nomor' => $s->no_pendaftaran,
                    'status' => $s->status, 'label' => Tahap::labelStatus((string) $s->status),
                    'psb' => $tahap !== false && $s->status !== 'aktif',
                    'tahap' => $tahap === false ? null : $tahap,
                    'jumlah_tahap' => count(self::TAHAP_PSB) - 1,
                    'langkah' => self::LANGKAH[$s->status] ?? null,
                    'registrasi' => $s->status === 'calon' ? $s->tagihan->sum(fn ($t) => (float) $t->sisa) : null,
                    'final' => Tahap::isFinal((string) $s->status),
                    // Pengingat berkas PSB (bukan penghalang) — hanya untuk yang masih dalam proses pendaftaran.
                    'berkas_kurang' => $tahap !== false && $s->status !== 'aktif'
                        ? collect((new \App\Services\Modules\DokumenSantriService)->statusBerkas($s))->where('kelompok', 'registrasi')
                            ->whereIn('status', ['belum', 'perlu_diganti'])->count()
                        : 0,
                ];
            });

        // Kartu sorotan: sisa seluruh tagihan berlaku keluarga ini, dan saldo dompet bila ada anak aktif.
        $belumLunas = \App\Models\TagihanSantri::query()->berlaku()->where('status', '!=', 'lunas')
            ->whereIn('id_santri', Santri::where('id_wali', $wali->id)->pluck('id'))->get(['sisa']);
        $punyaDompet = PortalKeluargaController::punyaDompet();

        return view('portal.beranda', [
            'wali' => $wali, 'nama' => $wali->{"nama_{$peran}"} ?: $wali->nama, 'peran' => $peran, 'anak' => $anak,
            'sisaTagihan' => $belumLunas->sum(fn ($t) => (float) $t->sisa), 'lembarTagihan' => $belumLunas->count(),
            'adaTagihan' => \App\Models\TagihanSantri::whereIn('id_santri', Santri::where('id_wali', $wali->id)->pluck('id'))->exists(),
            'saldoDompet' => $punyaDompet ? (\App\Models\DompetWali::where('id_wali', $wali->id)->value('saldo') ?? 0) : null,
        ]);
    }

    // ---- Pendaftaran ----

    public function formDaftar(Request $request, PendaftaranPortal $pendaftaran): View
    {
        return view('portal.daftar', $pendaftaran->pilihan() + [
            'pendaftarBaru' => ! SesiWali::wali(),
            'emailPendaftar' => SesiWali::emailPendaftar($request),
            'sumber' => SumberInformasi::where('status', 'aktif')->orderBy('urutan')->orderBy('kode')->get(['kode', 'nama', 'butuh_keterangan']),
        ]);
    }

    /**
     * Data keluarga baru — kolomnya mengikuti modul Wali/Keluarga (keputusan user 13 Okt 2026).
     * Wajib: nama ayah & ibu, plus nama & HP peran pendaftar. Email pendaftar TIDAK
     * diterima dari form: ia email yang terbukti lewat OTP (lihat PendaftaranPortal).
     *
     * @return array<string,mixed>
     */
    private function aturanKeluargaBaru(string $peranPendaftar): array
    {
        $aturan = [
            'peran' => ['required', Rule::in(MasukWali::PERAN)],
            'nik' => ['nullable', 'string', 'max:32'],
            'alamat' => ['nullable', 'string', 'max:1000'],
        ];
        foreach (MasukWali::PERAN as $p) {
            $milikPendaftar = $p === $peranPendaftar;
            $aturan += [
                "nama_{$p}" => [$milikPendaftar || $p !== 'wali' ? 'required' : 'nullable', 'string', 'max:255'],
                "telepon_{$p}" => [$milikPendaftar ? 'required' : 'nullable', 'string', 'regex:/^[0-9+\-\s()]{8,20}$/'],
                "email_{$p}" => ['nullable', 'email', 'max:255'],
                "pekerjaan_{$p}" => ['nullable', Rule::in([...Wali::PEKERJAAN, Wali::PEKERJAAN_LAIN])],
                "pekerjaan_lain_{$p}" => ['nullable', "required_if:pekerjaan_{$p},".Wali::PEKERJAAN_LAIN, 'string', 'max:100'],
                "pendapatan_{$p}" => ['nullable', Rule::in(array_keys(Wali::PENDAPATAN))],
            ];
        }

        return $aturan;
    }

    public function simpanDaftar(Request $request, PendaftaranPortal $pendaftaran): RedirectResponse
    {
        $wali = SesiWali::wali();
        $baru = $wali === null;

        $data = $request->validate([
            'tahun_ajaran' => ['required', 'string'],
            'gelombang' => ['required', 'string'],
            'nama' => ['required', 'string', 'max:255'],
            'jenis_kelamin' => ['required', Rule::in(['L', 'P'])],
            'tempat_lahir' => ['nullable', 'string', 'max:255'],
            'tanggal_lahir' => ['nullable', 'date', 'before:today'],
            'asal_sekolah' => ['nullable', 'string', 'max:255'],
            'kode_jenjang' => ['required', 'string'],
            'tingkat' => ['required', 'integer', 'min:1'],
            'jalur' => ['required', 'string'],
            'sumber_informasi' => ['nullable', Rule::exists('sumber_informasi', 'kode')->where('status', 'aktif')],
            'sumber_informasi_lain' => ['nullable', 'string', 'max:255'],
            'versi_surat' => ['required', 'integer'],
            'setuju' => ['accepted'],
        ] + ($baru ? $this->aturanKeluargaBaru((string) $request->input('peran')) : []), [
            'setuju.accepted' => 'Surat pernyataan wajib dibaca dan disetujui.',
            'required' => ':Attribute wajib diisi.',
            'required_if' => 'Sebutkan pekerjaannya.',
            'email' => ':Attribute tidak sah.',
            'regex' => ':Attribute tidak sah.',
        ], collect(MasukWali::PERAN)->flatMap(fn ($p) => [
            "nama_{$p}" => "nama {$p}", "telepon_{$p}" => "nomor HP {$p}", "email_{$p}" => "email {$p}",
        ])->all());

        $peran = $baru ? $data['peran'] : SesiWali::peran($request);
        $email = $baru ? SesiWali::emailPendaftar($request) : MasukWali::normalEmail((string) $wali->{"email_{$peran}"});

        try {
            $santri = $pendaftaran->daftar($data, $wali, [
                'email' => $email, 'peran' => $peran, 'ip' => $request->ip(), 'user_agent' => $request->userAgent(),
            ]);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        if ($baru) {
            // Keluarganya baru saja lahir — pendaftar kini masuk sebagai wali biasa, dan
            // membuat password untuk masuk berikutnya (kode email cukup sekali).
            $this->sesi->masuk($santri->wali, $peran, $request, false);
            $request->session()->put(SesiWali::WAJIB_SANDI, true);

            return redirect()->route('portal.sandi.buat')
                ->with('status', "Pendaftaran {$santri->nama} diterima dengan nomor {$santri->no_pendaftaran}. Buat password untuk masuk berikutnya.");
        }

        return redirect()->route('portal.beranda')
            ->with('status', "Pendaftaran {$santri->nama} diterima dengan nomor {$santri->no_pendaftaran}.");
    }
}
