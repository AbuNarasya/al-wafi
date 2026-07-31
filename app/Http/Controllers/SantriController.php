<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\JadwalPerubahanSantri;
use App\Models\JalurNonaktif;
use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
use App\Models\PembayaranSantri;
use App\Models\Pendaftaran;
use App\Models\PotonganUangPangkal;
use App\Models\RencanaAngsuranUangPangkal;
use App\Models\Santri;
use App\Models\TahunAjaran;
use App\Models\TipeBiaya;
use App\Models\Wali;
use App\Services\Modules\JadwalPerubahanService;
use App\Services\Modules\KenaikanTingkatService;
use App\Services\Modules\PendaftaranLanjutanService;
use App\Services\Modules\PotonganGelombangService;
use App\Services\Modules\RekapPembayaranService;
use App\Services\Modules\SantriService;
use App\Services\Modules\SppService;
use App\Services\Modules\TahunAjaranService;
use App\Services\Modules\TarifService;
use App\Services\Ppsb\Tahap;
use App\Support\Akses;
use App\Support\Money;
use App\Support\Referensi;
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
    /**
     * Status yang termasuk "calon" (lingkup PPSB).
     *
     * `mengundurkan_diri` sengaja TIDAK di sini — ia berdaftar sendiri, sama
     * seperti alumni & santri keluar. Calon yang mundur sudah selesai urusannya
     * (tagihannya pun ditutup saat ia mundur), jadi membiarkannya berbaur di
     * daftar kerja PPSB hanya menaikkan angka pendaftar yang tak pernah datang.
     */
    private const CALON = ['calon', 'terbayar', 'terverifikasi', 'diseleksi', 'diterima', 'lolos_kesehatan', 'tidak_lulus', 'gagal_medcheck'];

    /**
     * Status per daftar. LIMA daftar terpisah, bukan satu daftar bercampur:
     * alumni & santri keluar dulu ikut nongol di daftar Santri Kependidikan,
     * sehingga jumlah "santri" di layar tak pernah sama dengan jumlah santri yang
     * benar-benar bersekolah. Pemisahannya murni tampilan — barisnya tetap satu
     * tabel, jadi tagihan bersisa milik alumni tetap bisa ditagih.
     */
    private const LINGKUP = [
        'calon' => self::CALON,
        'siap_aktivasi' => ['siap_aktivasi'],
        'aktif' => ['aktif'],
        'alumni' => ['alumni'],
        'keluar' => ['keluar'],
        'mundur' => ['mengundurkan_diri'],
    ];

    /** Judul halaman & nama rute per lingkup — dipakai judul, tombol Reset, & tautan Kembali. */
    private const JUDUL = [
        'calon' => 'Calon Santri',
        'siap_aktivasi' => 'Calon Santri Siap Aktivasi',
        'aktif' => 'Santri Aktif',
        'alumni' => 'Alumni',
        'keluar' => 'Santri Keluar',
        'mundur' => 'Calon Mengundurkan Diri',
    ];

    private const RUTE = [
        'calon' => 'santri.calon',
        'siap_aktivasi' => 'santri.siap_aktivasi',
        'aktif' => 'santri.aktif',
        'alumni' => 'santri.alumni',
        'keluar' => 'santri.keluar',
        'mundur' => 'santri.mundur',
    ];

    /**
     * Daftar tempat sebuah STATUS ditampilkan — untuk tautan "Kembali".
     * Hanya status yang punya daftar sendiri; sisanya jatuh ke daftar Calon.
     */
    private const RUTE_STATUS = [
        'siap_aktivasi' => 'santri.siap_aktivasi',
        'aktif' => 'santri.aktif',
        'alumni' => 'santri.alumni',
        'keluar' => 'santri.keluar',
        'mengundurkan_diri' => 'santri.mundur',
    ];

    public function __construct(private readonly SantriService $service) {}

    /** @param 'calon'|'aktif'|'alumni'|'keluar' $lingkup */
    public function index(Request $request, string $lingkup): View
    {
        // Perubahan yang tahun ajarannya sudah dimulai dinyalakan di sini juga.
        // Inilah daftar yang paling sering dibuka, jadi paling mungkin menjadi
        // pemicu yang benar-benar berjalan — cron harian tak bisa diandalkan di
        // paket gratis yang tidur. Idempoten, dan hanya menyentuh baris yang
        // memang jatuh tempo.
        (new KenaikanTingkatService)->terapkanYangJatuhTempo();

        $q = trim((string) $request->query('q', ''));
        // Filter per kolom. Sengaja DIKERJAKAN SERVER (bukan komponen rowFilter
        // yang menyaring di browser) karena daftar ini berpaginasi — filter sisi
        // browser hanya akan menyaring 25 baris yang sedang tampil.
        $fJenjang = trim((string) $request->query('jenjang', ''));
        $fTingkat = trim((string) $request->query('tingkat', ''));
        $fJalur = trim((string) $request->query('jalur', ''));
        $fStatus = trim((string) $request->query('status', ''));
        $fBayar = trim((string) $request->query('bayar', ''));

        $statusLingkup = self::LINGKUP[$lingkup] ?? self::LINGKUP['aktif'];

        // `jenjang` ikut dimuat supaya kolomnya bisa menyebut NAMA jenjang tanpa
        // satu kueri per baris — kode `J001` tak bercerita apa pun bagi pembaca.
        $rows = Santri::query()->with(['wali', 'jalurPendaftaran', 'jenjang'])
            // Daftar CALON juga memuat santri yang sedang MELANJUTKAN ke jenjang
            // berikutnya. Statusnya sengaja tetap `aktif` (ia masih bersekolah &
            // masih ditagih SPP sampai kenaikannya dieksekusi), jadi tanpa baris
            // ini pekerjaan PPSB atas mereka — seleksi, med check — tak pernah
            // muncul di daftar tempat PPSB bekerja.
            ->when($lingkup === 'calon',
                fn ($query) => $query->where(fn ($w) => $w
                    ->whereIn('status', $statusLingkup)
                    ->orWhereHas('pendaftaranSemua', fn ($p) => $p->lanjutan()->terbuka())),
                fn ($query) => $query->whereIn('status', $statusLingkup))
            // NIS LAMA ikut dicari. Sejak nomornya diterbitkan ulang tiap naik
            // jenjang, nomor di kartu atau rapor yang dibawa wali sering kali
            // bukan lagi nomor yang berlaku — dan itulah satu-satunya pegangan
            // mereka saat bertanya di meja administrasi.
            ->when($q !== '', fn ($query) => $query->where(
                fn ($w) => $w->where('nama', 'ilike', "%{$q}%")->orWhere('no_pendaftaran', 'ilike', "%{$q}%")
                    ->orWhere('nisn', 'ilike', "%{$q}%")->orWhere('nis', 'ilike', "%{$q}%")
                    ->orWhereHas('riwayatNis', fn ($n) => $n->where('nis', 'ilike', "%{$q}%")),
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
        $jalur = JalurPendaftaran::orderByRaw('created_at ASC NULLS FIRST')
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
            'bayar' => (new RekapPembayaranService)->ringkasMassal($rows->pluck('id')),
            // Nominal SPP hanya bermakna bagi santri yang sudah bersekolah, jadi
            // kolomnya cuma muncul di daftar Kependidikan — bukan di Calon Santri.
            'spp' => $lingkup === 'aktif'
                ? (new SppService)->ringkasMassal($rows->getCollection())
                : [],
            'filter' => ['jenjang' => $fJenjang, 'tingkat' => $fTingkat, 'jalur' => $fJalur, 'status' => $fStatus, 'bayar' => $fBayar],
            'opsiJenjang' => Referensi::jenjang(),
            // Pilihan tingkat mengikuti jenjang yang sedang disaring; tanpa
            // penyaring jenjang, ditawarkan sebanyak jenjang terpanjang.
            'opsiTingkat' => $this->opsiTingkat($fJenjang),
            // Daftar berstatus TUNGGAL (alumni, keluar) tak perlu penyaring status:
            // dropdown berisi satu pilihan hanya menambah sel yang tak berguna.
            'opsiStatus' => count($statusLingkup) > 1
                ? collect($statusLingkup)->mapWithKeys(fn ($s) => [$s => Tahap::labelStatus($s)])->all()
                : [],
            'judul' => self::JUDUL[$lingkup] ?? 'Santri',
            'rute' => self::RUTE[$lingkup] ?? 'santri.aktif',
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
        $idMenunggu = PembayaranSantri::where('status', 'menunggu_verifikasi')
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

    public function edit(int $id): View
    {
        $santri = Santri::findOrFail($id);

        return view('santri.edit', [
            'santri' => $santri,
            'waliOptions' => Wali::where('status', 'aktif')->orderBy('nama')->get()
                ->mapWithKeys(fn ($w) => [$w->id => "{$w->nama} ({$w->telepon})"])->all(),
            'kembali' => route('santri.show', $santri->id),
        ]);
    }

    /**
     * Simpan suntingan data santri.
     *
     * YANG SENGAJA TIDAK BISA DISUNTING DI SINI, beserta alasannya:
     *  • `status` — punya mesin transisi sendiri (Tahap) dengan pemeriksaan
     *    berjenjang; mengubahnya lewat form biasa akan melompati semuanya.
     *  • `jalur`, `gelombang`, `tahun_ajaran` — ketiganya menentukan tarif yang
     *    dipakai saat tagihan TERBIT. Mengubahnya belakangan tidak menghitung
     *    ulang tagihan yang sudah ada, jadi data santri dan tagihannya akan
     *    saling bertentangan tanpa pesan apa pun. Potongan gelombang bahkan
     *    sudah terlanjur melekat pada tagihan uang pangkalnya.
     *  • `no_pendaftaran` — nomor dokumen yang sudah tercetak & dirujuk.
     *  • `nominal_spp` — sudah punya jalurnya sendiri (SPP → nominal khusus).
     */
    public function update(Request $request, int $id): RedirectResponse
    {
        $santri = Santri::findOrFail($id);

        $data = $request->validate([
            'id_wali' => ['required', 'integer', 'exists:wali,id'],
            'nama' => ['required', 'string', 'max:255'],
            'jenis_kelamin' => ['required', 'in:L,P'],
            'tempat_lahir' => ['nullable', 'string', 'max:255'],
            'tanggal_lahir' => ['nullable', 'date'],
            // NIS terbit saat daftar ulang; boleh dikoreksi bila salah ketik,
            // tetapi tak boleh bentrok dengan santri lain.
            'nis' => ['nullable', 'string', 'max:255', Rule::unique('santri', 'nis')->ignore($santri->id)],
            'nisn' => ['nullable', 'string', 'max:255'],
            'kode_jenjang' => ['required', 'string', Rule::exists('jenjang', 'kode')->where('status', 'aktif')],
            'tingkat' => ['required', 'integer', 'min:1'],
            'asal_sekolah' => ['nullable', 'string', 'max:255'],
            'alamat_sekolah_asal' => ['nullable', 'string'],
            'kepala_sekolah_asal' => ['nullable', 'string', 'max:255'],
            'cp_kepala_sekolah_asal' => ['nullable', 'string', 'max:255'],
            'sumber_informasi' => ['nullable', Rule::exists('sumber_informasi', 'kode')->where('status', 'aktif')],
            'sumber_informasi_lain' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->service->update($id, $data);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('santri.show', $id)->with('status', 'Data santri diperbarui.');
    }

    /**
     * Tautan "Kembali" pada halaman detail — halaman ini dipakai EMPAT daftar
     * (Calon Santri, Santri, Alumni, Santri Keluar), jadi tujuannya tak boleh
     * dipaku ke salah satunya.
     *
     * Diutamakan halaman ASAL supaya pencarian, penyaring, dan nomor halaman
     * tak hilang; hanya diterima bila memang salah satu daftar santri, agar
     * Referer dari mana pun tidak bisa mengarahkan tombol ini ke sembarang URL.
     * Kalau tak ada, jatuh ke daftar yang sesuai STATUS santrinya — membawa
     * penyaring jenjangnya, yang persis menu asalnya. Sejak alumni & santri
     * keluar punya daftar sendiri, memaku semuanya ke daftar Santri akan
     * mengantar orang ke halaman yang bahkan tak memuat santri itu.
     */
    private function tautanKembali(Santri $santri): string
    {
        $asal = url()->previous();
        $path = parse_url($asal, PHP_URL_PATH) ?? '';
        $daftar = ['/ppsb/calon-santri', '/ppsb/calon-mundur', '/kesantrian/santri', '/kesantrian/alumni', '/kesantrian/santri-keluar'];
        if (in_array($path, $daftar, true)) {
            return $asal;
        }

        if (in_array($santri->status, self::CALON, true)) {
            return route('santri.calon');
        }

        $rute = self::RUTE_STATUS[$santri->status] ?? 'santri.aktif';

        return route($rute, array_filter(['jenjang' => $santri->kode_jenjang]));
    }

    /**
     * Opsi penyaring Tingkat: [1 => 'Tingkat 1', …].
     *
     * @return array<int,string>
     */
    private function opsiTingkat(string $kodeJenjang): array
    {
        $peta = Jenjang::petaTingkat();
        // Tanpa penyaring jenjang, ditawarkan SELURUH rentang yang ada — sejak
        // penomorannya berkelanjutan, rentang gabungan itu justru bermakna
        // (1–12), bukan lagi sekadar "sebanyak jenjang terpanjang".
        $mulai = $kodeJenjang !== '' ? ($peta[$kodeJenjang]['mulai'] ?? 0) : (min(array_column($peta, 'mulai') ?: [0]));
        $akhir = $kodeJenjang !== '' ? ($peta[$kodeJenjang]['akhir'] ?? 0) : (max(array_column($peta, 'akhir') ?: [0]));

        $hasil = [];
        for ($i = max(1, $mulai); $i <= $akhir; $i++) {
            $hasil[$i] = "Tingkat {$i}";
        }

        return $hasil;
    }

    public function create(): View
    {
        $taService = new TahunAjaranService;

        return view('santri.create', [
            'santri' => new Santri(['jenis_kelamin' => 'L']),
            'waliOptions' => Wali::where('status', 'aktif')->orderBy('nama')->get()
                ->mapWithKeys(fn ($w) => [$w->id => "{$w->nama} ({$w->telepon})"])->all(),
            'taOptions' => $taService->opsiAktif(),
            'taDefault' => $taService->defaultPendaftaran()?->kode,
            // Jalur berlaku LINTAS tahun ajaran, jadi seluruhnya dikirim; yang
            // tidak berlaku untuk (T.A, jenjang) tertentu disaring di layar dari
            // peta di bawah, dan ditolak lagi oleh SantriService saat menyimpan.
            'jalurOptions' => Referensi::jalur(),
            'jalurNonaktif' => JalurNonaktif::all(['tahun_ajaran', 'kode_jenjang', 'kode_jalur'])
                ->groupBy(fn ($n) => $n->tahun_ajaran.'|'.$n->kode_jenjang)
                ->map(fn ($g) => $g->pluck('kode_jalur')->values()->all())->all(),
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
            'sumber_informasi' => ['nullable', Rule::exists('sumber_informasi', 'kode')->where('status', 'aktif')],
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
        $santri = Santri::with(['wali', 'tagihan.jenis', 'pendaftaran', 'jenjang', 'jalurPendaftaran'])->findOrFail($id);

        // Apa yang MASIH BISA diterbitkan — bukan sekadar "uang pangkalnya sudah
        // ada?". Calon berjalur bebas uang pangkal tak pernah punya tagihan itu,
        // sehingga penjaga lama tak pernah terpenuhi dan formnya terus muncul
        // walau perlengkapannya — satu-satunya yang memang terbit — sudah ada.
        $bisa = $this->bisaDitagihkan($santri);
        $potonganUangPangkal = null;
        $nominalDefaultUangPangkal = null;
        $nominalDefaultPerlengkapan = null;
        $asalTarifUangPangkal = null;
        $asalTarifPerlengkapan = null;
        $sppSantri = null;
        if (in_array($santri->status, ['diterima', 'lolos_kesehatan'], true) && $bisa['ada']) {
            $potonganUangPangkal = (new PotonganGelombangService)
                ->potonganAktif($santri->gelombang, $santri->kode_jenjang, $santri->tahun_ajaran);

            // Angka default diambil dari grid Tarif, dan ASAL-nya ikut ditampilkan
            // supaya petugas tahu sel mana yang terpakai — bukan sekadar melihat
            // angka yang entah dari mana. Tetap boleh diubah saat menagihkan.
            $tarifUp = $this->tarifKomponen($santri, 'uang_pangkal');
            $nominalDefaultUangPangkal = $tarifUp['nominal'];
            $asalTarifUangPangkal = $tarifUp;

            $tarifPlk = $this->tarifKomponen($santri, 'perlengkapan');
            $nominalDefaultPerlengkapan = $tarifPlk['nominal'];
            $asalTarifPerlengkapan = $tarifPlk;

            // SPP ikut DITAMPILKAN di sini walau tak ada tagihan yang terbit
            // sekarang: SPP baru mulai ditagih setelah daftar ulang, dan inilah
            // saat wali duduk membicarakan seluruh biayanya. Yang tersimpan
            // hanya KEBIJAKAN nominalnya (kolom `santri.nominal_spp`) — pintu
            // yang sama dengan modal Nominal Khusus di modul SPP.
            $sppSantri = $this->sppKomponen($santri);
        }

        // Nominal per tagihan yang sudah disetor tapi belum diverifikasi keuangan:
        // tanpa ini tabel Tagihan tampak seolah belum dibayar sama sekali, karena
        // `sisa` baru berkurang saat pembayaran diverifikasi.
        $menungguPerTagihan = (new RekapPembayaranService)->menungguPerTagihan($id);

        // Koreksi nominal (salah input) — hanya selama belum diakrualkan.
        $tagihanUangPangkal = $santri->tagihan->first(fn ($t) => TipeBiaya::perilakuDari($t->jenis?->tipe) === 'uang_pangkal');
        $koreksiUangPangkal = null;
        if ($tagihanUangPangkal && ! $tagihanUangPangkal->sudah_akrual && $tagihanUangPangkal->status !== 'batal') {
            $potonganRow = PotonganUangPangkal::where('id_tagihan', $tagihanUangPangkal->id)->first();
            $koreksiUangPangkal = [
                'tagihan' => $tagihanUangPangkal,
                'potongan' => ($potonganRow && $potonganRow->status !== 'hangus') ? $potonganRow : null,
                'nominal_normal' => $potonganRow?->nominal_normal ?? $tagihanUangPangkal->nominal,
                'terbayar' => PembayaranSantri::where('id_tagihan', $tagihanUangPangkal->id)
                    ->where('status', 'terverifikasi')->sum('nominal'),
                'menunggu' => PembayaranSantri::where('id_tagihan', $tagihanUangPangkal->id)
                    ->where('status', 'menunggu_verifikasi')->count(),
                'rencana_aktif' => RencanaAngsuranUangPangkal::where('id_tagihan', $tagihanUangPangkal->id)
                    ->where('status', 'aktif')->exists(),
            ];
        }

        // Koreksi nominal perlengkapan — pagar sama, tanpa urusan potongan.
        $tagihanPerlengkapan = $santri->tagihan->first(fn ($t) => TipeBiaya::perilakuDari($t->jenis?->tipe) === 'perlengkapan');
        $koreksiPerlengkapan = null;
        if ($tagihanPerlengkapan && ! $tagihanPerlengkapan->sudah_akrual && $tagihanPerlengkapan->status !== 'batal') {
            $koreksiPerlengkapan = [
                'tagihan' => $tagihanPerlengkapan,
                'terbayar' => PembayaranSantri::where('id_tagihan', $tagihanPerlengkapan->id)
                    ->where('status', 'terverifikasi')->sum('nominal'),
                'menunggu' => PembayaranSantri::where('id_tagihan', $tagihanPerlengkapan->id)
                    ->where('status', 'menunggu_verifikasi')->count(),
                'rencana_aktif' => RencanaAngsuranUangPangkal::where('id_tagihan', $tagihanPerlengkapan->id)
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
                'sisa' => (string) $hidup->reduce(fn ($s, $t) => Money::add($s, $t->sisa), '0'),
                'akrual' => $hidup->contains(fn ($t) => (bool) $t->sudah_akrual),
                'menunggu' => $hidup->sum(fn ($t) => PembayaranSantri::where('id_tagihan', $t->id)
                    ->where('status', 'menunggu_verifikasi')->count()),
            ];
        }

        return view('santri.show', [
            'santri' => $santri,
            'labelStatus' => Tahap::labelStatus($santri->status),
            'transisi' => Tahap::TRANSISI[$santri->status] ?? [],
            'bisaTagih' => $bisa,
            'potonganUangPangkal' => $potonganUangPangkal,
            'nominalDefaultUangPangkal' => $nominalDefaultUangPangkal,
            'nominalDefaultPerlengkapan' => $nominalDefaultPerlengkapan,
            'asalTarifUangPangkal' => $asalTarifUangPangkal,
            'asalTarifPerlengkapan' => $asalTarifPerlengkapan,
            'sppSantri' => $sppSantri,
            'bolehUbahSpp' => Akses::boleh('santri', 'ubah'),
            'opsiTingkat' => Jenjang::find($santri->kode_jenjang)?->opsiTingkat() ?? [],
            'opsiTaBerjalan' => array_values((new TahunAjaranService)->opsiAktif()),
            'bebasUangPangkal' => $this->tarifKomponen($santri, 'uang_pangkal')['bebas'],
            'kembali' => $this->tautanKembali($santri),
            'koreksiUangPangkal' => $koreksiUangPangkal,
            'koreksiPerlengkapan' => $koreksiPerlengkapan,
            'keluarAktif' => $keluarAktif,
            'menungguPerTagihan' => $menungguPerTagihan,
            // Kenaikan jenjang internal lewat proses PPSB. Sasarannya null bila
            // santri ini memang tak bisa naik (jenjang terakhir → alumni).
            'lanjutan' => $this->bahanLanjutan($santri),
        ]);
    }

    /**
     * Tarif satu komponen bagi seorang santri, dalam bentuk siap-pakai untuk
     * layar: nominal default, penanda bebas, dan kalimat asal angkanya.
     *
     * Kegagalan mencari master TIDAK dilempar ke atas — halaman detail santri
     * harus tetap terbuka walau setelan tarifnya belum lengkap; pesannyalah yang
     * ditampilkan di tempat isian, bukan halaman error.
     *
     * `bagian` dioper apa adanya supaya <x-asal-tarif> bisa menebalkan nama
     * jenjang, jalur, dan tahun ajarannya; null bila kalimatnya bukan kalimat
     * asal tarif (mis. pesan galat), dan komponennya jatuh ke `label` polos.
     *
     * @return array{nominal:?string,bebas:bool,label:?string,bagian:?array}
     */
    private function tarifKomponen(Santri $santri, string $perilaku): array
    {
        try {
            $tarif = $this->service->komponen($perilaku, $santri->tahun_ajaran, $santri->kode_jenjang, $santri->jalur)['tarif'];
        } catch (AppException $e) {
            return ['nominal' => null, 'bebas' => false, 'label' => $e->getMessage(), 'bagian' => null];
        }

        return [
            'nominal' => $tarif['status'] === 'ada' ? $tarif['nominal'] : null,
            'bebas' => $tarif['status'] === 'bebas',
            'label' => $tarif['label'],
            'bagian' => $tarif['bagian'] ?? null,
        ];
    }

    /**
     * Bahan blok "SPP Bulanan" pada form penagihan: angka yang AKAN dipakai saat
     * SPP-nya nanti diterbitkan, beserta asalnya.
     *
     * Pagarnya sama dengan tarifKomponen(): kegagalan mencari master TIDAK
     * dilempar ke atas — halaman calon santri harus tetap terbuka walau sel
     * tarif SPP-nya belum diisi atau bertanda Bebas, dan pesannyalah yang
     * ditampilkan di tempat isian. `khusus` tetap dilaporkan dari kolomnya
     * sendiri: santri bernominal khusus harus tetap terbaca demikian walau
     * pencarian tarif jenjangnya gagal.
     *
     * @return array{nominal:?string,khusus:bool,label:string,bagian:?array,keterangan:?string}
     */
    private function sppKomponen(Santri $santri): array
    {
        try {
            $spp = (new SppService)->nominalSppSantri($santri->id);
        } catch (AppException $e) {
            return [
                'nominal' => $santri->nominal_spp !== null ? Money::of($santri->nominal_spp) : null,
                'khusus' => $santri->nominal_spp !== null,
                'label' => $e->getMessage(),
                'bagian' => null,
                'keterangan' => $santri->keterangan_spp,
            ];
        }

        return [
            'nominal' => $spp['nominal'],
            'khusus' => $spp['asal'] === 'khusus',
            'label' => $spp['asal_label'],
            'bagian' => $spp['asal_bagian'] ?? null,
            'keterangan' => $spp['keterangan'],
        ];
    }

    /**
     * Bahan panel "Jenjang Lanjutan" di halaman santri: sasaran kenaikan, siklus
     * yang sedang berjalan (bila ada), riwayatnya, dan usulan tarif tujuan.
     *
     * Sengaja mengembalikan `null` untuk santri yang bukan calon naik — supaya
     * panelnya tak muncul sama sekali, bukan muncul lalu menolak.
     */
    private function bahanLanjutan(Santri $santri): ?array
    {
        if ($santri->status !== 'aktif') {
            return null;
        }
        $svc = new PendaftaranLanjutanService;
        $sasaran = $svc->sasaran($santri);
        // Jenjang & jalur dimuat karena layar menyebut NAMA-nya, bukan kodenya.
        $berjalan = Pendaftaran::with(['jenjang', 'jalur'])
            ->where('id_santri', $santri->id)->lanjutan()->terbuka()
            ->orderByDesc('id')->first();
        if (! $sasaran && ! $berjalan) {
            return null;
        }

        $tarif = new TarifService;
        $ta = $berjalan->tahun_ajaran ?? null;
        $jenjangTujuan = $berjalan->kode_jenjang ?? $sasaran['kode_jenjang'] ?? null;
        $jalurTujuan = $berjalan->kode_jalur ?? $sasaran['kode_jalur'] ?? null;

        return [
            'sasaran' => $sasaran,
            'berjalan' => $berjalan,
            'riwayat' => Pendaftaran::with('jenjang')->where('id_santri', $santri->id)->lanjutan()
                ->orderByDesc('id')->get(),
            'opsiTa' => TahunAjaran::where('status', 'aktif')
                ->where('kode', '!=', (string) $santri->taBerjalan())
                ->orderBy('kode')->pluck('kode', 'kode')->all(),
            'opsiTingkat' => $jenjangTujuan ? (Jenjang::find($jenjangTujuan)?->opsiTingkat() ?? []) : [],
            // Usulan angka untuk form eksekusi — tetap boleh ditimpa petugas.
            'tarif' => $ta && $jenjangTujuan ? [
                'uang_pangkal' => $tarif->cari('uang_pangkal', $ta, $jenjangTujuan, $jalurTujuan),
                'perlengkapan' => $tarif->cari('perlengkapan', $ta, $jenjangTujuan, $jalurTujuan),
            ] : null,
        ];
    }

    /** Uang pangkal santri ini masih bisa ditagihkan? (menentukan wajib/tidaknya isian nominal) */
    private function bisaTagihUangPangkal(int $id): bool
    {
        $santri = Santri::with('tagihan')->find($id);

        return $santri ? $this->bisaDitagihkan($santri)['uang_pangkal'] : false;
    }

    /**
     * Komponen yang masih terbuka, untuk dioper ke `tagihkanUangPangkal`.
     *
     * @return list<string>
     */
    private function komponenTerbuka(int $id): array
    {
        $santri = Santri::with('tagihan')->find($id);
        if (! $santri) {
            return ['uang_pangkal', 'perlengkapan'];
        }
        $bisa = $this->bisaDitagihkan($santri);
        $terbuka = array_values(array_filter(
            ['uang_pangkal', 'perlengkapan'],
            fn ($k) => $bisa[$k],
        ));

        // Kosong → kembalikan keduanya, bukan larik kosong: yang kosong akan
        // membuat service diam-diam tak menerbitkan apa pun. Biarkan penjaganya
        // sendiri yang menolak dengan pesan yang menyebut sebabnya.
        return $terbuka ?: ['uang_pangkal', 'perlengkapan'];
    }

    /**
     * Komponen mana yang MASIH bisa diterbitkan untuk santri ini.
     *
     * Sebuah komponen habis bila tarifnya BEBAS (memang tak dipungut) atau
     * tagihannya sudah ada. Tagihan `batal` tidak dihitung — setelah dibatalkan,
     * santrinya memang boleh ditagih ulang, dan `SantriService::tagihkanUangPangkal`
     * memakai aturan yang sama.
     *
     * Perlengkapan juga habis bila selnya belum diisi: tanpa tarif, tak ada angka
     * yang bisa ditawarkan, dan isian kosong tak akan menerbitkan apa pun.
     *
     * @return array{uang_pangkal:bool, perlengkapan:bool, ada:bool}
     */
    private function bisaDitagihkan(Santri $santri): array
    {
        $hidup = fn (string $perilaku) => $santri->tagihan->contains(
            fn ($t) => $t->perilaku === $perilaku
                && $t->kode_jenjang === $santri->kode_jenjang
                && $t->tahun_ajaran === $santri->tahun_ajaran
                && $t->status !== 'batal',
        );

        $up = $this->tarifKomponen($santri, 'uang_pangkal');
        $plk = $this->tarifKomponen($santri, 'perlengkapan');

        $bisaUp = ! $up['bebas'] && ! $hidup('uang_pangkal');
        $bisaPlk = ! $plk['bebas'] && $plk['nominal'] !== null && ! $hidup('perlengkapan');

        return ['uang_pangkal' => $bisaUp, 'perlengkapan' => $bisaPlk, 'ada' => $bisaUp || $bisaPlk];
    }

    /**
     * Menerbitkan uang pangkal & perlengkapan, sekaligus menyimpan kebijakan
     * nominal SPP bila petugas menekan "ubah" pada blok SPP.
     *
     * SPP-nya SENGAJA tidak ikut menerbitkan tagihan: SPP baru mulai ditagih
     * setelah daftar ulang, jadi yang masuk akal disimpan sekarang hanyalah
     * ANGKANYA. `ubah_spp` adalah penanda niat — isian SPP baru ada di DOM
     * setelah "ubah" diklik, jadi tanpa penanda itu `nominal_spp` tetap NULL
     * dan santrinya terus mengikuti tarif jenjang, ikut naik saat tarif naik.
     */
    private function tagihUangPangkal(Request $request, int $id): array
    {
        $data = $request->validate([
            // Isian nominal hanya ADA di layar bila uang pangkalnya memang masih
            // bisa ditagihkan — tarifnya bebas, atau tagihannya sudah terbit,
            // keduanya membuat isiannya ditiadakan. Mewajibkannya di sini akan
            // menolak kiriman yang formnya sendiri tak menyediakan.
            'nominal' => [
                $this->bisaTagihUangPangkal($id) ? 'required' : 'nullable',
                'numeric', 'gt:0',
            ],
            'nominal_perlengkapan' => ['nullable', 'numeric', 'min:0'],
            'jatuh_tempo' => ['nullable', 'date'],
            'keterangan' => ['nullable', 'string', 'max:255'],
            // Nol sah (beasiswa penuh), kosong = kembali ke tarif jenjang.
            'ubah_spp' => ['nullable', 'boolean'],
            'nominal_spp' => ['nullable', 'numeric', 'min:0'],
            'keterangan_spp' => ['nullable', 'string', 'max:255'],
        ]);

        // Komponen yang MASIH terbuka saja. Tanpa ini, menerbitkan perlengkapan
        // untuk santri yang uang pangkalnya sudah terbit akan ditolak 409 —
        // service memeriksa keduanya sekaligus.
        $hasil = $this->service->tagihkanUangPangkal($id, $data + ['komponen' => $this->komponenTerbuka($id)]);

        if ($request->boolean('ubah_spp') && Akses::boleh('santri', 'ubah')) {
            (new SppService)->setNominalKhusus(
                Santri::findOrFail($id),
                $data['nominal_spp'] ?? null,
                $data['keterangan_spp'] ?? null,
            );
        }

        return $hasil;
    }

    /**
     * AKTIVASI MASSAL — satu angkatan diaktifkan sekaligus dari daftar "Calon
     * Santri Siap Aktivasi".
     *
     * Satu baris gagal TIDAK menahan yang lain: jumlahnya bisa ratusan, dan
     * menahan seluruh angkatan karena satu tagihan yang keburu dibatalkan
     * berarti tak ada yang bisa masuk sama sekali. Yang gagal disebutkan namanya.
     */
    public function aktivasiMassal(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'id_santri' => ['required', 'array'],
            'id_santri.*' => ['integer', 'exists:santri,id'],
        ]);

        $idJadwal = JadwalPerubahanSantri::whereIn('id_santri', $data['id_santri'])
            ->where('keputusan', 'aktivasi')->where('status', 'siap')->pluck('id')->all();

        try {
            $hasil = (new JadwalPerubahanService)->terapkanSekarang($idJadwal);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        $pesan = "{$hasil['diterapkan']} santri diaktifkan.";
        if ($hasil['gagal'] !== []) {
            $nama = Santri::whereIn('id', array_column($hasil['gagal'], 'id_santri'))->pluck('nama')->join(', ');
            return back()->with('error', $pesan." GAGAL: {$nama} — ".$hasil['gagal'][0]['pesan']);
        }

        return back()->with('status', $pesan);
    }

    /**
     * Nyalakan jadwal aktivasi seorang santri SEKARANG, tanpa menunggu tanggalnya.
     *
     * Lewat jadwalnya — bukan memanggil `aktifkan()` langsung — supaya barisnya
     * ikut ditandai `diterapkan` dan tak menyala dua kali saat tanggalnya tiba.
     */
    private function aktifkanSekarang(int $id): Santri
    {
        $jadwal = JadwalPerubahanSantri::where('id_santri', $id)
            ->where('keputusan', 'aktivasi')->where('status', 'siap')->orderByDesc('id')->first();
        if (! $jadwal) {
            throw new AppException(422, 'Santri ini tidak punya jadwal aktivasi yang menunggu. '
                .'Tandai dulu "Siap di Aktifkan" dari halaman calon santri.');
        }

        $hasil = (new JadwalPerubahanService)->terapkanSekarang([$jadwal->id]);
        if ($hasil['gagal'] !== []) {
            throw new AppException(422, $hasil['gagal'][0]['pesan']);
        }

        return Santri::findOrFail($id);
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
                // SPP-nya ikut di form yang sama tetapi TIDAK menerbitkan apa pun.
                'tagih-uang-pangkal' => $this->tagihUangPangkal($request, $id),
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
                // Koreksi tahun berjalan — jalan keluar bagi santri hasil impor
                // yang kolom tahun ajarannya salah, dan bagi yang dilewati
                // kenaikan tingkat karena selisih tahunnya lebih dari satu.
                'set-tahun-berjalan' => $this->service->setTahunBerjalan($id, $request->validate([
                    'tahun_ajaran_berjalan' => ['required', 'string', 'exists:tahun_ajaran,kode'],
                ])['tahun_ajaran_berjalan']),
                // Menandai SIAP, bukan mengaktifkan: jurnal akrual & status aktif
                // menyusul saat T.A masuknya dimulai (atau lewat tombol manual).
                'siap-aktivasi' => $this->service->siapkanAktivasi($id, $request->user()->id_pengguna),
                // Tombol manual per santri — untuk yang masuk di tengah tahun
                // ajaran, menunggu 1 Juli berikutnya menahannya setahun penuh.
                'aktifkan-sekarang' => $this->aktifkanSekarang($id),
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
