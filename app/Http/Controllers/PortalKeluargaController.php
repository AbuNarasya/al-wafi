<?php

namespace App\Http\Controllers;

use App\Models\DompetSantri;
use App\Models\MutasiDompet;
use App\Models\NotifikasiWali;
use App\Models\PembayaranSantri;
use App\Models\PerangkatWali;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Services\Modules\DompetService;
use App\Services\Modules\RincianBelanja;
use App\Services\PortalWali\IngatPerangkat;
use App\Services\PortalWali\MasukWali;
use App\Services\PortalWali\SesiWali;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * PORTAL WALI — Tagihan, Dompet, Akun. MURNI BACA (kecuali mencabut perangkat sendiri).
 *
 * Setiap kueri berangkat dari keluarga yang sedang masuk. Nomor anak di alamat
 * hanya MEMILIH di antara anak keluarga itu — mengganti angkanya tak pernah
 * membuka anak keluarga lain.
 *
 * Aturan pembayaran (keputusan 12 Okt 2026): santri aktif membayar tagihan dari
 * Dompet Wali (seketika, tanpa verifikasi); calon membayar langsung per tagihan
 * (transfer = menunggu verifikasi). Karena itu "menunggu verifikasi" pada
 * tagihan hanya mungkin muncul untuk calon, dan selalu ditampilkan terpisah
 * dari angka terbayar — uang itu belum diakui.
 */
class PortalKeluargaController extends Controller
{
    /** Dompet hanya untuk keluarga yang punya anak berstatus aktif. */
    public const STATUS_DOMPET = ['aktif'];

    private const LABEL_MUTASI = [
        // Distribusi kini dua arah (wali ⇄ uang saku) — rinciannya dibaca dari keterangan mutasi.
        'topup' => 'Isi dompet', 'distribusi_keluar' => 'Pindah saldo keluar', 'distribusi_masuk' => 'Pindah saldo masuk',
        'tabung_keluar' => 'Ke tabungan', 'tabung_masuk' => 'Masuk tabungan', 'bayar_tagihan' => 'Bayar tagihan',
        'jajan' => 'Belanja', 'tarik' => 'Penarikan',
    ];

    private const KELUAR = ['distribusi_keluar', 'tabung_keluar', 'bayar_tagihan', 'jajan', 'tarik'];

    /** Uang keluar? `jajan` dua arah (− belanja, + pengembalian batal/retur), jadi tandanya yang menentukan. */
    private static function keluar(MutasiDompet $m): bool
    {
        if ($m->jenis === 'jajan') {
            return (float) $m->nominal < 0;
        }

        return (float) $m->nominal < 0 || in_array($m->jenis, self::KELUAR, true);
    }

    private static function labelMutasi(MutasiDompet $m): string
    {
        return $m->jenis === 'jajan' && (float) $m->nominal > 0 ? 'Pengembalian belanja' : (self::LABEL_MUTASI[$m->jenis] ?? $m->jenis);
    }

    private const LABEL_SUMBER = ['dompet_wali' => 'Dompet Wali', 'xendit' => 'Pembayaran online', 'midtrans' => 'Virtual Account (online)', 'manual' => 'Transfer / tunai'];

    /** @return Collection<int,Santri> */
    private function anakKeluarga(): Collection
    {
        return Santri::with('jenjang:kode,nama')->where('id_wali', SesiWali::wali()->id)
            ->orderBy('nama')->get(['id', 'nama', 'status', 'kode_jenjang', 'tingkat', 'id_wali']);
    }

    public static function punyaDompet(): bool
    {
        $wali = SesiWali::wali();

        return $wali !== null && Santri::where('id_wali', $wali->id)->whereIn('status', self::STATUS_DOMPET)->exists();
    }

    /**
     * Nama tagihan untuk WALI: jenis biaya + periode ("SPP SMP · November 2026"),
     * untuk SEMUA perilaku — tagihan lain-lain bulanan pun butuh bulannya, kalau
     * tidak empat baris "Excul Robotic" tak bisa dibedakan. Keterangan yang ditulis
     * petugas tampil terpisah lewat keteranganTagihan().
     */
    public static function namaTagihan(TagihanSantri $t): string
    {
        $nama = $t->jenis?->nama ?? (self::keteranganTagihan($t) ?? $t->kode_jenis);
        $periode = trim((string) $t->periode);
        if (preg_match('/^\d{4}-\d{2}$/', $periode)) {
            $nama .= ' · '.\Illuminate\Support\Carbon::createFromFormat('Y-m-d', $periode.'-01')->locale('id')->translatedFormat('F Y');
        } elseif ($periode !== '' && ! str_contains(mb_strtolower($nama), mb_strtolower($periode))) {
            // Tagihan lain-lain boleh berperiode teks bebas ("Semester Ganjil").
            $nama .= ' · '.$periode;
        }

        return $nama;
    }

    /**
     * Keterangan tagihan yang BERMAKNA bagi wali ("Tunggakan SPP 3 bulan"), atau null.
     *
     * `keterangan` juga dipakai sistem untuk catatan pembukuan ("SPP SMP 2026-11 ·
     * akrual SPP-2610-0002", "… · koreksi KR-…: alasan"). Potongan pembukuan itu
     * dibuang, dan keterangan bawaan yang hanya mengulang nama jenis (+ periode /
     * T.A / tingkat) disembunyikan — isinya sudah tampil di judul.
     */
    public static function keteranganTagihan(TagihanSantri $t): ?string
    {
        $bagian = array_filter(
            array_map('trim', explode(' · ', (string) $t->keterangan)),
            fn ($b) => $b !== '' && ! preg_match('/^(akrual|koreksi|bebas \(nominal nol\)|hangus|dibatalkan)\b/iu', $b),
        );
        $ket = implode(' · ', $bagian);
        if ($ket === '') {
            return null;
        }
        $nama = (string) ($t->jenis?->nama ?? '');
        if ($nama !== '' && preg_match('/^'.preg_quote($nama, '/').'(\s+(\d{4}-\d{2}|periode\s.*|T\.A\s.*))?$/iu', $ket)) {
            return null;
        }
        if ($t->periode && mb_strtolower($ket) === mb_strtolower(trim($nama.' '.$t->periode))) {
            return null;
        }

        return $ket;
    }

    // ---- Tagihan ----

    public function tagihan(Request $request): View
    {
        $anak = $this->anakKeluarga();
        $tab = $request->query('tab') === 'riwayat' ? 'riwayat' : 'tagihan';

        // RINGKASAN PER ANAK lebih dulu bila anaknya lebih dari satu: membuka
        // langsung rincian satu anak membuat wali mengira itulah seluruh tagihannya.
        if ($anak->count() > 1 && ! $request->has('anak')) {
            $belum = TagihanSantri::query()->berlaku()->where('status', '!=', 'lunas')
                ->whereIn('id_santri', $anak->pluck('id'))->get(['id_santri', 'sisa'])->groupBy('id_santri');

            return view('portal.tagihan-keluarga', [
                'anak' => $anak->map(fn (Santri $s) => [
                    'id' => $s->id, 'nama' => $s->nama, 'jenjang' => $s->jenjang?->nama ?? $s->kode_jenjang,
                    'tingkat' => $s->tingkat, 'status' => \App\Services\Ppsb\Tahap::labelStatus((string) $s->status),
                    'sisa' => ($belum[$s->id] ?? collect())->sum(fn ($t) => (float) $t->sisa),
                    'lembar' => ($belum[$s->id] ?? collect())->count(),
                ]),
                'total' => $belum->flatten()->sum(fn ($t) => (float) $t->sisa),
                'va' => \App\Services\PortalWali\PembayaranPortal::vaMenunggu(SesiWali::wali()->id),
            ]);
        }

        // Dipilih DI ANTARA anak keluarga ini saja — id asing jatuh ke anak pertama.
        $dipilih = $anak->firstWhere('id', (int) $request->query('anak')) ?? $anak->first();

        $data = ['anak' => $anak, 'dipilih' => $dipilih, 'tagihan' => collect(), 'riwayat' => collect(),
            'ringkasan' => null, 'tab' => $tab, 'va' => collect()];
        if (! $dipilih) {
            return view('portal.tagihan', $data);
        }

        // Lewat query(): model juga punya method biasa bernama berlaku() — panggilan statis menabraknya.
        $tagihan = TagihanSantri::query()->berlaku()->with('jenis:kode,nama')->where('id_santri', $dipilih->id)
            ->orderByRaw("CASE WHEN status = 'lunas' THEN 1 ELSE 0 END")->orderBy('jatuh_tempo')->orderBy('id')->get();
        $bayar = PembayaranSantri::with('tagihan.jenis:kode,nama')->where('id_santri', $dipilih->id)
            ->whereIn('status', ['terverifikasi', 'menunggu_verifikasi'])
            ->orderByDesc('tanggal')->orderByDesc('id')->get();

        $diakui = $bayar->where('status', 'terverifikasi')->groupBy('id_tagihan')
            ->map(fn ($g) => $g->reduce(fn ($t, $p) => Money::add($t, $p->nominal), '0'));
        $menunggu = $bayar->where('status', 'menunggu_verifikasi')->groupBy('id_tagihan')
            ->map(fn ($g) => $g->reduce(fn ($t, $p) => Money::add($t, $p->nominal), '0'));
        // VA belum dibayar yang memuat tagihan anak ini — kotaknya memberi jalan "Lanjutkan bayar" / "Ganti metode".
        $data['va'] = \App\Services\PortalWali\PembayaranPortal::vaMenunggu(SesiWali::wali()->id, $tagihan->pluck('id')->all());
        $diVa = fn (int $id) => $data['va']->reduce(fn ($n, $p) => Money::add($n, \App\Services\PortalWali\PembayaranPortal::bagianVa($p, $id)), '0');

        $data['tagihan'] = $tagihan->map(fn (TagihanSantri $t) => [
            'id' => $t->id,
            'alur' => $alur = \App\Services\PortalWali\PembayaranPortal::alur($t->setRelation('santri', $dipilih))[0],
            // Masih ada setoran yang menunggu & menutup seluruh sisanya → tak ada lagi yang perlu dibayar.
            'bisa_bayar' => $alur !== null && (float) \App\Support\Money::sub($t->sisa, $menunggu[$t->id] ?? '0') > 0,
            'nama' => self::namaTagihan($t), 'keterangan' => self::keteranganTagihan($t),
            'periode' => $t->periode, 'jatuh_tempo' => $t->jatuh_tempo,
            'nominal' => Money::of($t->nominal), 'sisa' => Money::of($t->sisa),
            'terbayar' => $diakui[$t->id] ?? '0', 'menunggu' => $menunggu[$t->id] ?? '0', 'di_va' => $diVa($t->id),
            'status' => (float) $t->sisa <= 0 ? 'lunas' : ((float) ($diakui[$t->id] ?? 0) > 0 ? 'sebagian' : 'belum'),
        ]);
        $data['ringkasan'] = [
            'nominal' => $tagihan->reduce(fn ($t, $x) => Money::add($t, $x->nominal), '0'),
            'sisa' => $tagihan->reduce(fn ($t, $x) => Money::add($t, $x->sisa), '0'),
            'menunggu' => $menunggu->reduce(fn ($t, $x) => Money::add($t, $x), '0'),
        ];
        $data['riwayat'] = $bayar->map(fn (PembayaranSantri $p) => [
            'tanggal' => $p->tanggal, 'nominal' => Money::of($p->nominal), 'status' => $p->status,
            'tagihan' => $p->tagihan ? self::namaTagihan($p->tagihan) : 'Pembayaran',
            'keterangan' => $p->tagihan ? self::keteranganTagihan($p->tagihan) : null,
            'sumber' => self::LABEL_SUMBER[$p->sumber] ?? 'Transfer / tunai',
        ]);

        return view('portal.tagihan', $data);
    }

    // ---- Riwayat tagihan keluarga (ikon Tagihan di beranda) ----

    /** SELURUH tagihan & pembayaran semua anak — termasuk yang sudah lunas. */
    public function riwayatTagihan(Request $request): View
    {
        $anak = $this->anakKeluarga();
        $idAnak = $anak->pluck('id');
        $nama = $anak->pluck('nama', 'id');

        $tagihan = TagihanSantri::query()->berlaku()->with('jenis:kode,nama')->whereIn('id_santri', $idAnak)
            ->orderByDesc('created_at')->orderByDesc('id')->get();
        $bayar = PembayaranSantri::with('tagihan.jenis:kode,nama')->whereIn('id_santri', $idAnak)
            ->whereIn('status', ['terverifikasi', 'menunggu_verifikasi'])->orderByDesc('tanggal')->orderByDesc('id')->get();

        return view('portal.riwayat-tagihan', [
            'tab' => $request->query('tab') === 'bayar' ? 'bayar' : 'tagihan',
            'total' => [
                'nominal' => $tagihan->sum(fn ($t) => (float) $t->nominal),
                'sisa' => $tagihan->sum(fn ($t) => (float) $t->sisa),
                'terbayar' => $bayar->where('status', 'terverifikasi')->sum(fn ($p) => (float) $p->nominal),
                'menunggu' => $bayar->where('status', 'menunggu_verifikasi')->sum(fn ($p) => (float) $p->nominal),
            ],
            'tagihan' => $tagihan->map(fn (TagihanSantri $t) => [
                'id' => $t->id, 'anak' => $nama[$t->id_santri] ?? '', 'nama' => self::namaTagihan($t),
                'keterangan' => self::keteranganTagihan($t),
                'nominal' => (float) $t->nominal, 'sisa' => (float) $t->sisa, 'terbit' => $t->created_at,
                'status' => (float) $t->sisa <= 0 ? 'lunas' : ((float) $t->sisa < (float) $t->nominal ? 'sebagian' : 'belum'),
            ]),
            'bayar' => $bayar->map(fn (PembayaranSantri $p) => [
                'anak' => $nama[$p->id_santri] ?? '', 'tanggal' => $p->tanggal, 'nominal' => (float) $p->nominal,
                'tagihan' => $p->tagihan ? self::namaTagihan($p->tagihan) : 'Pembayaran',
                'keterangan' => $p->tagihan ? self::keteranganTagihan($p->tagihan) : null,
                'sumber' => self::LABEL_SUMBER[$p->sumber] ?? 'Transfer / tunai', 'menunggu' => $p->status === 'menunggu_verifikasi',
            ]),
        ]);
    }

    // ---- Mutasi dompet anak (ikon Dompet di beranda) ----

    /** Histori uang saku & tabungan seluruh anak, boleh disaring per anak. */
    /**
     * Rincian barang di balik satu mutasi belanja — hanya mutasi uang saku anak keluarga yang sedang masuk
     * (selain itu 404, tanpa membedakan "tak ada" dan "bukan milikmu").
     */
    public function rincianMutasi(int $id): JsonResponse
    {
        $m = MutasiDompet::where('pemilik', 'santri')->where('jenis', 'jajan')->findOrFail($id);
        $milik = DompetSantri::whereKey($m->id_dompet)
            ->whereHas('santri', fn ($q) => $q->where('id_wali', SesiWali::wali()->id)->whereIn('status', self::STATUS_DOMPET))
            ->exists();
        abort_unless($milik, 404);

        return response()->json([
            'nominal' => abs((float) $m->nominal), 'keluar' => self::keluar($m), 'tanggal' => $m->tanggal?->format('d/m/Y'),
            'keterangan' => $m->keterangan, 'rincian' => RincianBelanja::untukMutasi($m),
        ]);
    }

    public function mutasiAnak(Request $request): View|RedirectResponse
    {
        $anak = Santri::with(['dompet', 'tabungan'])->where('id_wali', SesiWali::wali()->id)
            ->whereIn('status', self::STATUS_DOMPET)->orderBy('nama')->get();
        if ($anak->isEmpty()) {
            return redirect()->route('portal.beranda')->with('error', 'Dompet aktif setelah anak Anda berstatus santri aktif.');
        }
        // Saringan HANYA memilih di antara anak keluarga ini.
        $dipilih = $anak->firstWhere('id', (int) $request->query('anak'));
        $sasaran = $dipilih ? collect([$dipilih]) : $anak;

        $pemilikDompet = [];
        foreach ($sasaran as $s) {
            if ($s->dompet) {
                $pemilikDompet[] = ['santri', $s->dompet->id, $s->nama];
            }
            if ($s->tabungan) {
                $pemilikDompet[] = ['tabungan', $s->tabungan->id, $s->nama];
            }
        }
        $namaPer = collect($pemilikDompet)->mapWithKeys(fn ($d) => [$d[0].':'.$d[1] => $d[2]]);

        $mutasi = $pemilikDompet === [] ? collect() : MutasiDompet::where(function ($q) use ($pemilikDompet) {
            foreach ($pemilikDompet as [$pemilik, $id]) {
                $q->orWhere(fn ($w) => $w->where('pemilik', $pemilik)->where('id_dompet', $id));
            }
        })->where('status', '!=', 'ditolak')->orderByDesc('tanggal')->orderByDesc('id')->limit(100)->get();

        return view('portal.mutasi-anak', [
            'anak' => $anak, 'dipilih' => $dipilih,
            'saldoSaku' => $sasaran->sum(fn ($s) => (float) ($s->dompet?->saldo ?? 0)),
            'saldoTabungan' => $sasaran->sum(fn ($s) => (float) ($s->tabungan?->saldo ?? 0)),
            'mutasi' => $mutasi->map(fn (MutasiDompet $m) => [
                'tanggal' => $m->tanggal, 'anak' => $namaPer[$m->pemilik.':'.$m->id_dompet] ?? '',
                'dompet' => $m->pemilik === 'tabungan' ? 'Tabungan' : 'Uang saku',
                'label' => self::labelMutasi($m), 'keterangan' => $m->keterangan,
                'nominal' => abs((float) $m->nominal),
                'keluar' => self::keluar($m),
                'menunggu' => $m->status === 'menunggu_verifikasi',
                'rinci' => $m->jenis === 'jajan' ? route('portal.dompet.rincian', $m->id) : null,
            ]),
        ]);
    }

    // ---- Detail anak ----

    /** Semua yang berkaitan dengan SATU anak — hanya anak keluarga yang sedang masuk (selain itu 404). */
    public function anak(int $id): View
    {
        $s = Santri::with(['jenjang:kode,nama', 'kelas:id,nama', 'dompet', 'tabungan'])->where('id_wali', SesiWali::wali()->id)->findOrFail($id);

        $tagihan = TagihanSantri::query()->berlaku()->with('jenis:kode,nama')->where('id_santri', $s->id)->get();
        $belum = $tagihan->where('status', '!=', 'lunas')->sortBy('jatuh_tempo')->values();
        $aktif = $s->status === 'aktif';
        $tahapPsb = \App\Services\Ppsb\Tahap::isFinal((string) $s->status) || $aktif ? null
            : array_search($s->status, ['calon', 'terbayar', 'terverifikasi', 'diseleksi', 'diterima', 'lolos_kesehatan', 'siap_aktivasi'], true);

        $mutasi = collect();
        if ($aktif) {
            $dompetAnak = array_filter(['santri' => $s->dompet?->id, 'tabungan' => $s->tabungan?->id]);
            $mutasi = MutasiDompet::where(function ($q) use ($dompetAnak) {
                foreach ($dompetAnak as $pemilik => $idDompet) {
                    $q->orWhere(fn ($w) => $w->where('pemilik', $pemilik)->where('id_dompet', $idDompet));
                }
            })->when($dompetAnak === [], fn ($q) => $q->whereRaw('false'))
                ->where('status', '!=', 'ditolak')->orderByDesc('tanggal')->orderByDesc('id')->limit(10)->get()
                ->map(fn (MutasiDompet $m) => [
                    'tanggal' => $m->tanggal, 'dompet' => $m->pemilik === 'tabungan' ? 'Tabungan' : 'Uang saku',
                    'label' => self::labelMutasi($m), 'keterangan' => $m->keterangan,
                    'nominal' => abs((float) $m->nominal), 'keluar' => self::keluar($m),
                    'rinci' => $m->jenis === 'jajan' ? route('portal.dompet.rincian', $m->id) : null,
                ]);
        }

        // Daftar periksa berkas PSB — pengingat, bukan penghalang (keputusan user 15 Okt 2026).
        $registrasi = collect((new \App\Services\Modules\DokumenSantriService)->statusBerkas($s))->where('kelompok', 'registrasi');

        return view('portal.anak', [
            'berkas' => \App\Services\Ppsb\Tahap::isFinal((string) $s->status) ? null : [
                'total' => $registrasi->count(),
                'kurang' => $registrasi->whereIn('status', ['belum', 'perlu_diganti'])->count(),
                'diganti' => $registrasi->where('status', 'perlu_diganti')->count(),
            ],
            'santri' => $s, 'aktif' => $aktif, 'tahapPsb' => $tahapPsb === false ? null : $tahapPsb,
            'label' => \App\Services\Ppsb\Tahap::labelStatus((string) $s->status),
            'sisa' => $belum->sum(fn ($t) => (float) $t->sisa),
            'belum' => $belum->take(5)->map(fn (TagihanSantri $t) => [
                'id' => $t->id, 'nama' => self::namaTagihan($t), 'keterangan' => self::keteranganTagihan($t),
                'sisa' => (float) $t->sisa, 'jatuh_tempo' => $t->jatuh_tempo,
            ]),
            'jumlahBelum' => $belum->count(),
            'riwayat' => PembayaranSantri::with('tagihan.jenis:kode,nama')->where('id_santri', $s->id)
                ->whereIn('status', ['terverifikasi', 'menunggu_verifikasi'])->orderByDesc('tanggal')->orderByDesc('id')->limit(5)->get(),
            'mutasi' => $mutasi,
            // Uang saku kegiatan: sesi yang tampil untuk anak ini + pengajuannya (bila ada).
            'kegiatan' => $aktif ? (new \App\Services\Modules\PenarikanTunaiService)->sesiUntukWali(SesiWali::wali())
                ->filter(fn ($sesi) => $sesi->santri->contains('id', $s->id))
                ->map(fn ($sesi) => ['sesi' => $sesi, 'pengajuan' => $sesi->pengajuan->firstWhere('id_santri', $s->id)])->values() : collect(),
            // Kartu belanja Wafi Mart (santri aktif saja).
            'belanja' => $aktif ? [
                'kartu' => $s->kartuAktif()->first(),
                'milik' => $s->dompet?->batas_harian,
                'batas' => \App\Services\Modules\KartuSantriService::batasHarian($s->id),
                'bawaan' => \App\Services\Modules\KartuSantriService::batasBawaan(),
                'punyaPin' => \App\Services\Modules\KartuSantriService::punyaPin($s->id),
            ] : null,
        ]);
    }

    // ---- Dompet ----

    public function dompet(DompetService $dompet): View|RedirectResponse
    {
        if (! self::punyaDompet()) {
            return redirect()->route('portal.beranda')
                ->with('error', 'Dompet aktif setelah anak Anda berstatus santri aktif.');
        }

        $wali = $dompet->ringkasanWali(SesiWali::wali()->id);
        $mutasi = $wali->dompet
            ? MutasiDompet::where('pemilik', 'wali')->where('id_dompet', $wali->dompet->id)
                ->where('status', '!=', 'ditolak')->orderByDesc('tanggal')->orderByDesc('id')->limit(20)->get()
            : collect();

        return view('portal.dompet', [
            'autoDebet' => (bool) $wali->auto_debet,
            'saldoWali' => $wali->dompet?->saldo ?? '0',
            'anak' => $wali->santri->whereIn('status', self::STATUS_DOMPET)->values(),
            'sesiTarik' => (new \App\Services\Modules\PenarikanTunaiService)->sesiUntukWali($wali),
            'mutasi' => $mutasi->map(fn (MutasiDompet $m) => [
                'tanggal' => $m->tanggal, 'label' => self::LABEL_MUTASI[$m->jenis] ?? $m->jenis,
                'keterangan' => $m->keterangan, 'nominal' => Money::of($m->nominal),
                'keluar' => in_array($m->jenis, self::KELUAR, true), 'menunggu' => $m->status === 'menunggu_verifikasi',
            ]),
        ]);
    }

    // ---- Kabar ----

    /** Kabar untuk ORANG yang sedang masuk (keluarga + peran) yang belum dibaca. */
    public static function kabarBelumDibaca(): int
    {
        $wali = SesiWali::wali();

        return $wali ? NotifikasiWali::where('id_wali', $wali->id)->where('peran', SesiWali::peran(request()))
            ->whereNull('dibaca_pada')->count() : 0;
    }

    public function kabar(Request $request): View
    {
        $kabar = NotifikasiWali::where('id_wali', SesiWali::wali()->id)->where('peran', SesiWali::peran($request))
            ->orderByDesc('id')->limit(50)->get();
        $belum = $kabar->whereNull('dibaca_pada')->pluck('id');

        // Dibuka = dibaca. Penanda "baru" tetap tampil pada kunjungan ini saja.
        if ($belum->isNotEmpty()) {
            NotifikasiWali::whereIn('id', $belum)->update(['dibaca_pada' => now()]);
        }

        return view('portal.kabar', ['kabar' => $kabar, 'baru' => $belum->all()]);
    }

    // ---- Akun ----

    public function akun(Request $request): View
    {
        $wali = SesiWali::wali();
        $peran = SesiWali::peran($request);
        $tokenIni = (string) $request->cookie(IngatPerangkat::COOKIE);

        return view('portal.akun', [
            'pushKunci' => app(\App\Services\Modules\PushService::class)->kunciPublik(),
            'kategori' => NotifikasiWali::KATEGORI,
            'kategoriNyala' => app(\App\Services\PortalWali\PushWaliService::class)->kategoriNyala($wali->id, $peran),
            'nama' => $wali->{"nama_{$peran}"} ?: $wali->nama,
            'peran' => \App\Models\Wali::PERAN[$peran] ?? $peran,
            'email' => MasukWali::samarkanEmail((string) $wali->{"email_{$peran}"}),
            'telepon' => $wali->{"telepon_{$peran}"} ?: $wali->telepon,
            'perangkat' => PerangkatWali::where('id_wali', $wali->id)->where('peran', $peran)
                ->whereNull('dicabut_pada')->where(fn ($q) => $q->whereNull('kedaluwarsa_pada')->orWhere('kedaluwarsa_pada', '>', now()))
                ->orderByDesc('terakhir_dipakai')->get()
                ->map(fn (PerangkatWali $p) => ['id' => $p->id, 'nama' => $p->nama_perangkat,
                    'terakhir' => $p->terakhir_dipakai,
                    'ini' => $tokenIni !== '' && hash_equals($p->token_hash, hash('sha256', $tokenIni))]),
        ]);
    }

    /** Cabut perangkat MILIK ORANG INI SAJA (keluarga + peran yang sedang masuk). */
    public function cabutPerangkat(Request $request, int $id): RedirectResponse
    {
        $jumlah = PerangkatWali::where('id', $id)->where('id_wali', SesiWali::wali()->id)
            ->where('peran', SesiWali::peran($request))->whereNull('dicabut_pada')
            ->update(['dicabut_pada' => now()]);

        return redirect()->route('portal.akun')->with($jumlah ? 'status' : 'error',
            $jumlah ? 'Perangkat dicabut. Perangkat itu harus memasukkan kode lagi untuk masuk.' : 'Perangkat tidak ditemukan.');
    }
}
