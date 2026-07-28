<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\Santri;
use App\Models\Wali;
use App\Services\Modules\SantriService;
use App\Services\Ppsb\Tahap;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            ->when($fJalur !== '', fn ($query) => $query->where('jalur', $fJalur))
            ->when($fStatus !== '' && in_array($fStatus, $statusLingkup, true), fn ($query) => $query->where('status', $fStatus))
            ->when($fBayar !== '', fn ($query) => $this->saringStatusBayar($query, $fBayar))
            ->orderByDesc('id')->paginate(25)->withQueryString();

        // Urutan kode jalur dipakai view untuk memberi warna label yang TETAP
        // per jalur (diambil dari seluruh master, bukan hanya baris di halaman
        // ini, agar warnanya konsisten antar-halaman).
        $jalurUrut = \App\Models\JalurPendaftaran::orderBy('kode')->pluck('nama', 'kode')->all();

        return view('santri.index', [
            'rows' => $rows,
            'q' => $q,
            'lingkup' => $lingkup,
            'jalurUrut' => $jalurUrut,
            // Status pembayaran mutakhir per baris (termasuk setoran yang belum
            // diverifikasi keuangan) — 2 query agregat untuk seluruh halaman.
            'bayar' => (new \App\Services\Modules\RekapPembayaranService)->ringkasMassal($rows->pluck('id')),
            'filter' => ['jenjang' => $fJenjang, 'jalur' => $fJalur, 'status' => $fStatus, 'bayar' => $fBayar],
            'opsiJenjang' => \App\Support\Referensi::jenjang(),
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

    public function create(): View
    {
        $taService = new \App\Services\Modules\TahunAjaranService;

        // Jalur aktif dikelompokkan per TA — form memfilter sesuai TA terpilih.
        $jalurPerTa = \App\Models\JalurPendaftaran::where('status', 'aktif')->orderBy('nama')->get()
            ->groupBy('tahun_ajaran')
            ->map(fn ($grup) => $grup->map(fn ($j) => ['v' => $j->kode, 'l' => $j->nama])->values())
            ->all();

        return view('santri.create', [
            'santri' => new Santri(['jenis_kelamin' => 'L']),
            'waliOptions' => Wali::where('status', 'aktif')->orderBy('nama')->get()
                ->mapWithKeys(fn ($w) => [$w->id => "{$w->nama} ({$w->telepon})"])->all(),
            'taOptions' => $taService->opsiAktif(),
            'taDefault' => $taService->defaultPendaftaran()?->kode,
            'jalurPerTa' => $jalurPerTa,
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
            'kode_jenjang' => ['nullable', 'string', 'max:255'],
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

        // Pengunduran diri santri AKTIF: pratinjau sisa uang pangkal yang akan dihapuskan.
        $keluarAktif = null;
        if ($santri->status === 'aktif') {
            $up = $tagihanUangPangkal && $tagihanUangPangkal->status !== 'batal' ? $tagihanUangPangkal : null;
            $keluarAktif = [
                'sisa' => $up?->sisa ?? '0',
                'akrual' => (bool) $up?->sudah_akrual,
                'menunggu' => $up ? \App\Models\PembayaranSantri::where('id_tagihan', $up->id)
                    ->where('status', 'menunggu_verifikasi')->count() : 0,
            ];
        }

        return view('santri.show', [
            'santri' => $santri,
            'labelStatus' => Tahap::labelStatus($santri->status),
            'transisi' => Tahap::TRANSISI[$santri->status] ?? [],
            'sudahAdaUangPangkal' => $sudahAdaUangPangkal,
            'potonganUangPangkal' => $potonganUangPangkal,
            'nominalDefaultUangPangkal' => $nominalDefaultUangPangkal,
            'koreksiUangPangkal' => $koreksiUangPangkal,
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
                'tagih-uang-pangkal' => $this->service->tagihkanUangPangkal($id, $request->validate([
                    'nominal' => ['required', 'numeric', 'gt:0'],
                    'jatuh_tempo' => ['nullable', 'date'],
                    'keterangan' => ['nullable', 'string', 'max:255'],
                ])),
                'koreksi-uang-pangkal' => $this->service->koreksiNominalUangPangkal($id, $request->validate([
                    'nominal' => ['required', 'numeric', 'gt:0'],
                    'jatuh_tempo' => ['nullable', 'date'],
                    'alasan' => ['required', 'string', 'max:255'],
                ]), $request->user()->id_pengguna),
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
