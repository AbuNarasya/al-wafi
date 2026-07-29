<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\Santri;
use App\Models\Wali;
use App\Services\Modules\SantriService;
use App\Services\Ppsb\Tahap;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Santri (calon → aktif). PPSB mengelola CALON (lingkup penerimaan); Kesantrian
 * mengelola santri AKTIF. Satu model, dibedakan filter status. Controller tipis
 * → SantriService (registrasi + state machine lifecycle).
 */
class SantriController extends Controller
{
    /** Status yang termasuk "calon" (lingkup PPSB). */
    private const CALON = ['calon', 'terbayar', 'terverifikasi', 'diseleksi', 'diterima', 'lolos_kesehatan', 'tidak_lulus', 'gagal_medcheck', 'mengundurkan_diri'];

    public function __construct(private readonly SantriService $service) {}

    /** @param 'calon'|'aktif' $lingkup */
    public function index(Request $request, string $lingkup): View
    {
        $q = trim((string) $request->query('q', ''));
        // Filter per kolom. Sengaja DIKERJAKAN SERVER (bukan komponen rowFilter
        // yang menyaring di browser) karena daftar ini berpaginasi — filter sisi
        // browser hanya akan menyaring 25 baris yang sedang tampil.
        $fJenjang = trim((string) $request->query('jenjang', ''));
        $fTingkat = trim((string) $request->query('tingkat', ''));
        $fJalur = trim((string) $request->query('jalur', ''));
        $fStatus = trim((string) $request->query('status', ''));
        $fBayar = trim((string) $request->query('bayar', ''));

        $statusLingkup = $lingkup === 'calon' ? self::CALON : ['aktif', 'alumni', 'keluar'];

        $rows = Santri::query()->with(['wali', 'jalurPendaftaran'])
            ->whereIn('status', $statusLingkup)
            ->when($q !== '', fn ($query) => $query->where(
                fn ($w) => $w->where('nama', 'ilike', "%{$q}%")->orWhere('no_pendaftaran', 'ilike', "%{$q}%")
                    ->orWhere('nisn', 'ilike', "%{$q}%")->orWhere('nis', 'ilike', "%{$q}%"),
            ))
            ->when($fJenjang !== '', fn ($query) => $query->where('kode_jenjang', $fJenjang))
            ->when($fTingkat !== '', fn ($query) => $query->where('tingkat', (int) $fTingkat))
            ->when($fJalur !== '', fn ($query) => $query->where('jalur', $fJalur))
            ->when($fStatus !== '' && in_array($fStatus, $statusLingkup, true), fn ($query) => $query->where('status', $fStatus))
            ->when($fBayar !== '', fn ($query) => $this->saringStatusBayar($query, $fBayar))
            ->orderByDesc('id')->paginate(25)->withQueryString();

        // Seluruh master jalur (bukan hanya baris di halaman ini) agar warna
        // labelnya konsisten antar-halaman. Diambil sekali, dipakai dua rupa:
        //
        //   $jalurWarna — urut WAKTU DIBUAT: jatah warna melekat pada jalur lama
        //     dan jalur baru selalu kebagian warna berikutnya. Kalau diurutkan
        //     abjad kode, menambah satu jalur akan MENGGESER warna semua jalur
        //     setelahnya — orang yang sudah hafal "biru = pindahan" jadi salah baca.
        //     created_at NULL (baris hasil impor SQL langsung) dianggap paling tua.
        //   $opsiJalur — urut kode, untuk dropdown filter (yang dicari orang abjad).
        $jalur = \App\Models\JalurPendaftaran::orderByRaw('created_at ASC NULLS FIRST')
            ->orderBy('kode')->pluck('nama', 'kode')->all();
        $opsiJalur = $jalur;
        ksort($opsiJalur);

        return view('santri.index', [
            'rows' => $rows,
            'q' => $q,
            'lingkup' => $lingkup,
            'jalurWarna' => $jalur,
            'opsiJalur' => $opsiJalur,
            // Status pembayaran mutakhir per baris (termasuk setoran yang belum
            // diverifikasi keuangan) — 2 query agregat untuk seluruh halaman.
            'bayar' => (new \App\Services\Modules\RekapPembayaranService)->ringkasMassal($rows->pluck('id')),
            'filter' => ['jenjang' => $fJenjang, 'tingkat' => $fTingkat, 'jalur' => $fJalur, 'status' => $fStatus, 'bayar' => $fBayar],
            'opsiJenjang' => \App\Support\Referensi::jenjang(),
            // Pilihan tingkat mengikuti jenjang yang sedang disaring; tanpa
            // penyaring jenjang, ditawarkan sebanyak jenjang terpanjang.
            'opsiTingkat' => $this->opsiTingkat($fJenjang),
            'opsiStatus' => collect($statusLingkup)
                ->mapWithKeys(fn ($s) => [$s => Tahap::labelStatus($s)])->all(),
        ]);
    }

    /**
     * Saring daftar berdasarkan status pembayaran. Definisinya HARUS sama dengan
     * RekapPembayaranService::statusRingkas() yang dipakai badge, kalau tidak
     * filternya akan menyembunyikan baris yang labelnya sendiri cocok:
     *   lunas    = punya tagihan, tak ada yang bersisa
     *   menunggu = masih bersisa & ada setoran menunggu verifikasi
     *   sebagian = masih bersisa, tanpa setoran menunggu, sebagian sudah terbayar
     *   belum    = masih bersisa, tanpa setoran menunggu, belum terbayar sepeser pun
     */
    private function saringStatusBayar($query, string $status)
    {
        $aktif = fn ($q) => $q->where('status', '!=', 'batal');
        $adaSisa = fn ($q) => $q->whereHas('tagihan', fn ($t) => $aktif($t)->where('sisa', '>', 0));
        $adaTerbayar = fn ($q) => $q->whereHas('tagihan', fn ($t) => $aktif($t)->whereColumn('sisa', '<', 'nominal'));
        $idMenunggu = \App\Models\PembayaranSantri::where('status', 'menunggu_verifikasi')
            ->distinct()->pluck('id_santri');

        return match ($status) {
            'tanpa_tagihan' => $query->whereDoesntHave('tagihan', fn ($t) => $aktif($t)),
            'lunas' => $query->whereHas('tagihan', fn ($t) => $aktif($t))
                ->whereDoesntHave('tagihan', fn ($t) => $aktif($t)->where('sisa', '>', 0)),
            'menunggu' => $adaSisa($query)->whereIn('id', $idMenunggu),
            'sebagian' => $adaTerbayar($adaSisa($query)->whereNotIn('id', $idMenunggu)),
            'belum' => $adaSisa($query)->whereNotIn('id', $idMenunggu)
                ->whereDoesntHave('tagihan', fn ($t) => $aktif($t)->whereColumn('sisa', '<', 'nominal')),
            default => $query,
        };
    }

    /**
     * Opsi penyaring Tingkat: [1 => 'Tingkat 1', …].
     *
     * @return array<int,string>
     */
    private function opsiTingkat(string $kodeJenjang): array
    {
        $peta = \App\Models\Jenjang::petaTingkat();
        $maks = $kodeJenjang !== '' ? ($peta[$kodeJenjang] ?? 0) : (max($peta ?: [0]));

        $hasil = [];
        for ($i = 1; $i <= $maks; $i++) {
            $hasil[$i] = "Tingkat {$i}";
        }

        return $hasil;
    }

    public function create(): View
    {
        $taService = new \App\Services\Modules\TahunAjaranService;

        return view('santri.create', [
            'santri' => new Santri(['jenis_kelamin' => 'L']),
            'waliOptions' => Wali::where('status', 'aktif')->orderBy('nama')->get()
                ->mapWithKeys(fn ($w) => [$w->id => "{$w->nama} ({$w->telepon})"])->all(),
            'taOptions' => $taService->opsiAktif(),
            'taDefault' => $taService->defaultPendaftaran()?->kode,
            // Jalur berlaku LINTAS tahun ajaran (kolom tahun_ajaran-nya sudah
            // dibuang), jadi daftarnya tidak lagi disaring per T.A.
            'jalurOptions' => \App\Support\Referensi::jalur(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'id_wali' => ['required', 'integer', 'exists:wali,id'],
            'nama' => ['required', 'string', 'max:255'],
            'jenis_kelamin' => ['required', 'in:L,P'],
            'tempat_lahir' => ['nullable', 'string', 'max:255'],
            'tanggal_lahir' => ['nullable', 'date'],
            'nisn' => ['nullable', 'string', 'max:255'],
            'asal_sekolah' => ['nullable', 'string', 'max:255'],
            'alamat_sekolah_asal' => ['nullable', 'string'],
            'kepala_sekolah_asal' => ['nullable', 'string', 'max:255'],
            'cp_kepala_sekolah_asal' => ['nullable', 'string', 'max:255'],
            // Jenjang kini WAJIB: tingkat wajib diisi, dan tingkat tak punya arti
            // tanpa jenjangnya (SDTQ tingkat 6 sah, SMP tingkat 6 tidak).
            'kode_jenjang' => ['required', 'string', Rule::exists('jenjang', 'kode')->where('status', 'aktif')],
            'tingkat' => ['required', 'integer', 'min:1'],
            'tahun_ajaran' => ['required', 'string', 'exists:tahun_ajaran,kode'],
            'jalur' => ['required', 'string', 'exists:jalur_pendaftaran,kode'],
            // Gelombang dipilih SADAR: "nomor" (isi angkanya) atau "tanpa"
            // (pindahan & kasus khusus → tak pernah dapat potongan gelombang).
            'gelombang_mode' => ['required', 'in:nomor,tanpa'],
            'gelombang' => ['nullable', 'required_if:gelombang_mode,nomor', 'integer', 'min:1'],
            // Pilihannya kini master (PPSB → Sumber Informasi), bukan daftar tetap.
            'sumber_informasi' => ['nullable', \Illuminate\Validation\Rule::exists('sumber_informasi', 'kode')->where('status', 'aktif')],
            'sumber_informasi_lain' => ['nullable', 'string', 'max:255'],
        ]);

        // "Tanpa Gelombang" disimpan sebagai NULL, bukan angka sentinel.
        if ($data['gelombang_mode'] === 'tanpa') {
            $data['gelombang'] = null;
        }
        unset($data['gelombang_mode']);

        try {
            $santri = $this->service->create($data);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('santri.show', $santri->id)->with('status', "Calon santri {$santri->nama} terdaftar ({$santri->no_pendaftaran}).");
    }

    public function show(int $id): View
    {
        $santri = Santri::with(['wali', 'tagihan.jenis', 'pendaftaran'])->findOrFail($id);

        // Uang pangkal: sudah ditagihkan? + pratinjau potongan gelombang (bila belum).
        $sudahAdaUangPangkal = $santri->tagihan->contains(fn ($t) => \App\Models\TipeBiaya::perilakuDari($t->jenis?->tipe) === 'uang_pangkal');
        $potonganUangPangkal = null;
        $nominalDefaultUangPangkal = null;
        $nominalDefaultPerlengkapan = null;
        if (in_array($santri->status, ['diterima', 'lolos_kesehatan'], true) && ! $sudahAdaUangPangkal) {
            $potonganUangPangkal = (new \App\Services\Modules\PotonganGelombangService)
                ->potonganAktif($santri->gelombang, $santri->kode_jenjang, $santri->tahun_ajaran);

            // Nominal default dari master (per jenjang + jalur, fallback umum) —
            // mengisi form penagihan tapi tetap boleh diubah petugas.
            try {
                $nominalDefaultUangPangkal = $this->service
                    ->jenisUangPangkal($santri->tahun_ajaran, $santri->kode_jenjang, $santri->jalur)->nominal;
            } catch (AppException) {
                $nominalDefaultUangPangkal = null; // master uang pangkal belum ada → biarkan kosong
            }
            try {
                $nominalDefaultPerlengkapan = $this->service
                    ->jenisPerlengkapan($santri->tahun_ajaran, $santri->kode_jenjang, $santri->jalur)->nominal;
            } catch (AppException) {
                $nominalDefaultPerlengkapan = null; // memang boleh tak dipungut
            }
        }

        // Nominal per tagihan yang sudah disetor tapi belum diverifikasi keuangan:
        // tanpa ini tabel Tagihan tampak seolah belum dibayar sama sekali, karena
        // `sisa` baru berkurang saat pembayaran diverifikasi.
        $menungguPerTagihan = (new \App\Services\Modules\RekapPembayaranService)->menungguPerTagihan($id);

        // Koreksi nominal (salah input) — hanya selama belum diakrualkan.
        $tagihanUangPangkal = $santri->tagihan->first(fn ($t) => \App\Models\TipeBiaya::perilakuDari($t->jenis?->tipe) === 'uang_pangkal');
        $koreksiUangPangkal = null;
        if ($tagihanUangPangkal && ! $tagihanUangPangkal->sudah_akrual && $tagihanUangPangkal->status !== 'batal') {
            $potonganRow = \App\Models\PotonganUangPangkal::where('id_tagihan', $tagihanUangPangkal->id)->first();
            $koreksiUangPangkal = [
                'tagihan' => $tagihanUangPangkal,
                'potongan' => ($potonganRow && $potonganRow->status !== 'hangus') ? $potonganRow : null,
                'nominal_normal' => $potonganRow?->nominal_normal ?? $tagihanUangPangkal->nominal,
                'terbayar' => \App\Models\PembayaranSantri::where('id_tagihan', $tagihanUangPangkal->id)
                    ->where('status', 'terverifikasi')->sum('nominal'),
                'menunggu' => \App\Models\PembayaranSantri::where('id_tagihan', $tagihanUangPangkal->id)
                    ->where('status', 'menunggu_verifikasi')->count(),
                'rencana_aktif' => \App\Models\RencanaAngsuranUangPangkal::where('id_tagihan', $tagihanUangPangkal->id)
                    ->where('status', 'aktif')->exists(),
            ];
        }

        // Koreksi nominal perlengkapan — pagar sama, tanpa urusan potongan.
        $tagihanPerlengkapan = $santri->tagihan->first(fn ($t) => \App\Models\TipeBiaya::perilakuDari($t->jenis?->tipe) === 'perlengkapan');
        $koreksiPerlengkapan = null;
        if ($tagihanPerlengkapan && ! $tagihanPerlengkapan->sudah_akrual && $tagihanPerlengkapan->status !== 'batal') {
            $koreksiPerlengkapan = [
                'tagihan' => $tagihanPerlengkapan,
                'terbayar' => \App\Models\PembayaranSantri::where('id_tagihan', $tagihanPerlengkapan->id)
                    ->where('status', 'terverifikasi')->sum('nominal'),
                'menunggu' => \App\Models\PembayaranSantri::where('id_tagihan', $tagihanPerlengkapan->id)
                    ->where('status', 'menunggu_verifikasi')->count(),
                'rencana_aktif' => \App\Models\RencanaAngsuranUangPangkal::where('id_tagihan', $tagihanPerlengkapan->id)
                    ->where('status', 'aktif')->exists(),
            ];
        }

        // Pengunduran diri santri AKTIF: pratinjau sisa yang akan dihapuskan —
        // uang pangkal DAN perlengkapan, karena keduanya ikut dibatalkan.
        $keluarAktif = null;
        if ($santri->status === 'aktif') {
            $hidup = collect([$tagihanUangPangkal, $tagihanPerlengkapan])
                ->filter(fn ($t) => $t && $t->status !== 'batal');
            $keluarAktif = [
                'sisa' => (string) $hidup->reduce(fn ($s, $t) => \App\Support\Money::add($s, $t->sisa), '0'),
                'akrual' => $hidup->contains(fn ($t) => (bool) $t->sudah_akrual),
                'menunggu' => $hidup->sum(fn ($t) => \App\Models\PembayaranSantri::where('id_tagihan', $t->id)
                    ->where('status', 'menunggu_verifikasi')->count()),
            ];
        }

        return view('santri.show', [
            'santri' => $santri,
            'labelStatus' => Tahap::labelStatus($santri->status),
            'transisi' => Tahap::TRANSISI[$santri->status] ?? [],
            'sudahAdaUangPangkal' => $sudahAdaUangPangkal,
            'potonganUangPangkal' => $potonganUangPangkal,
            'nominalDefaultUangPangkal' => $nominalDefaultUangPangkal,
            'nominalDefaultPerlengkapan' => $nominalDefaultPerlengkapan,
            'opsiTingkat' => \App\Models\Jenjang::find($santri->kode_jenjang)?->opsiTingkat() ?? [],
            'koreksiUangPangkal' => $koreksiUangPangkal,
            'koreksiPerlengkapan' => $koreksiPerlengkapan,
            'keluarAktif' => $keluarAktif,
            'menungguPerTagihan' => $menungguPerTagihan,
        ]);
    }

    /** Aksi lifecycle terpusat. */
    public function aksi(Request $request, int $id, string $aksi): RedirectResponse
    {
        try {
            match ($aksi) {
                'verifikasi' => $this->service->verifikasiBerkas($id),
                'seleksi' => $this->service->seleksi($id, $request->only(['nilai_baca', 'nilai_akademik', 'wawancara_wali', 'wawancara_santri', 'catatan'])),
                'pengumuman' => $this->service->pengumuman($id, ['lulus' => $request->boolean('lulus'), 'catatan' => $request->input('catatan')]),
                'medcheck' => $this->service->medcheck($id, ['lolos' => $request->boolean('lolos'), 'dokumen_lengkap' => $request->boolean('dokumen_lengkap'), 'catatan' => $request->input('catatan')]),
                // Satu form menerbitkan dua tagihan: uang pangkal (dipotong
                // potongan gelombang) & biaya perlengkapan (utuh, boleh kosong).
                'tagih-uang-pangkal' => $this->service->tagihkanUangPangkal($id, $request->validate([
                    'nominal' => ['required', 'numeric', 'gt:0'],
                    'nominal_perlengkapan' => ['nullable', 'numeric', 'min:0'],
                    'jatuh_tempo' => ['nullable', 'date'],
                    'keterangan' => ['nullable', 'string', 'max:255'],
                ])),
                'koreksi-uang-pangkal' => $this->service->koreksiNominalUangPangkal($id, $request->validate([
                    'nominal' => ['required', 'numeric', 'gt:0'],
                    'jatuh_tempo' => ['nullable', 'date'],
                    'alasan' => ['required', 'string', 'max:255'],
                ]), $request->user()->id_pengguna),
                'koreksi-perlengkapan' => $this->service->koreksiNominalPerlengkapan($id, $request->validate([
                    'nominal' => ['required', 'numeric', 'gt:0'],
                    'jatuh_tempo' => ['nullable', 'date'],
                    'alasan' => ['required', 'string', 'max:255'],
                ]), $request->user()->id_pengguna),
                // Santri lama (termasuk hasil impor) belum bertingkat — inilah
                // jalan mengisinya tanpa membuka seluruh data untuk disunting.
                'set-tingkat' => $this->service->setTingkat($id, (int) $request->validate([
                    'tingkat' => ['required', 'integer', 'min:1'],
                ])['tingkat']),
                'daftar-ulang' => $this->service->daftarUlang($id, $request->user()->id_pengguna),
                'undur-diri' => $this->service->mengundurkanDiri(
                    $id,
                    $request->validate(['alasan' => ['required', 'string', 'max:255']])['alasan'],
                    $request->user()->id_pengguna,
                ),
                default => abort(404),
            };
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('santri.show', $id)->with('status', 'Status santri diperbarui.');
    }
}
