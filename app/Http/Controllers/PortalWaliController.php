<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\Santri;
use App\Models\SumberInformasi;
use App\Models\Wali;
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

    public function formMasuk(Request $request): View|RedirectResponse
    {
        if (SesiWali::wali() && SesiWali::peran($request)) {
            return redirect()->route('portal.beranda');
        }

        return view('portal.masuk');
    }

    public function kirimKode(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);

        try {
            $this->masuk->mintaKode($data['email'], 'masuk', $request->ip());
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
        $request->session()->put(SesiWali::MENUNGGU, MasukWali::normalEmail($data['email']));

        return redirect()->route('portal.kode');
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
        $this->sesi->masuk($wali, $peran, $request, $ingat);

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

        $anak = Santri::with(['jenjang:kode,nama', 'tagihan' => fn ($q) => $q->where('perilaku', 'registrasi')
            ->whereNotIn('status', ['batal', 'dihapus'])])
            ->where('id_wali', $wali->id)->orderBy('nama')->get()
            ->map(function (Santri $s) {
                $tahap = array_search($s->status, self::TAHAP_PSB, true);

                return [
                    'id' => $s->id,
                    'nama' => $s->nama, 'jenjang' => $s->jenjang?->nama ?? $s->kode_jenjang, 'tingkat' => $s->tingkat,
                    'tahun_ajaran' => $s->tahun_ajaran, 'nomor' => $s->no_pendaftaran,
                    'status' => $s->status, 'label' => Tahap::labelStatus((string) $s->status),
                    'psb' => $tahap !== false && $s->status !== 'aktif',
                    'tahap' => $tahap === false ? null : $tahap,
                    'jumlah_tahap' => count(self::TAHAP_PSB) - 1,
                    'langkah' => self::LANGKAH[$s->status] ?? null,
                    'registrasi' => $s->status === 'calon' ? $s->tagihan->sum(fn ($t) => (float) $t->sisa) : null,
                    'final' => Tahap::isFinal((string) $s->status),
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
            'sumber' => SumberInformasi::where('status', 'aktif')->orderBy('urutan')->orderBy('kode')->get(['kode', 'nama', 'butuh_keterangan']),
        ]);
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
        ] + ($baru ? [
            'peran' => ['required', Rule::in(MasukWali::PERAN)],
            'nama_wali' => ['required', 'string', 'max:255'],
            'telepon' => ['required', 'string', 'regex:/^[0-9+\-\s()]{8,20}$/'],
            'alamat' => ['nullable', 'string', 'max:1000'],
        ] : []), [
            'setuju.accepted' => 'Surat pernyataan wajib dibaca dan disetujui.',
            'telepon.regex' => 'Nomor HP tidak sah.',
        ]);

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
            // Keluarganya baru saja lahir — pendaftar kini masuk sebagai wali biasa.
            $this->sesi->masuk($santri->wali, $peran, $request, false);
        }

        return redirect()->route('portal.beranda')
            ->with('status', "Pendaftaran {$santri->nama} diterima dengan nomor {$santri->no_pendaftaran}.");
    }
}
