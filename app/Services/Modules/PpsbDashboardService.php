<?php

namespace App\Services\Modules;

use App\Models\JenisBiaya;
use App\Models\PembayaranSantri;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\TahunAjaran;
use App\Models\TargetSantri;
use App\Models\TipeBiaya;
use App\Support\Money;
use App\Support\Referensi;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Angka Dashboard PPSB untuk SATU tahun ajaran.
 *
 * Tiga aturan yang berlaku di seluruh berkas ini (keputusan user 2026-07-28):
 *
 *  1. Hanya pembayaran **terverifikasi** yang dihitung — sama dengan definisi
 *     uang masuk di modul keuangan & rekap, supaya angka dashboard tak pernah
 *     bertentangan dengan laporan keuangan.
 *  2. Yang menentukan sebuah calon "terhitung" adalah PEMBAYARAN, bukan input
 *     data: pendaftar = sudah membayar registrasi, closing = sudah mulai
 *     membayar uang pangkal. Calon yang baru diinput tidak muncul di mana pun.
 *  3. Bulan diambil dari TANGGAL PEMBAYARAN (registrasi untuk pendaftar,
 *     pembayaran uang pangkal PERTAMA untuk closing), dan kolom bulan mengikuti
 *     siklus tahun ajaran Juli→Juni sehingga satu T.A tak terbelah dua tabel.
 */
class PpsbDashboardService
{
    /** Status pembayaran yang diakui sebagai uang masuk. */
    private const DIAKUI = 'terverifikasi';

    /** Tahun ajaran default: yang dipakai pendaftaran, lalu yang aktif. */
    public function taDefault(): ?string
    {
        return TahunAjaran::where('default_pendaftaran', true)->value('kode')
            ?? TahunAjaran::where('status', 'aktif')->orderByDesc('kode')->value('kode')
            ?? TahunAjaran::orderByDesc('kode')->value('kode');
    }

    /** @return array<string,string> opsi pemilih tahun ajaran */
    public function opsiTa(): array
    {
        return TahunAjaran::orderByDesc('kode')->pluck('kode', 'kode')->all();
    }

    /**
     * 12 bulan MUSIM PENERIMAAN sebuah tahun ajaran: Juli setahun sebelum T.A
     * dimulai sampai Juni saat T.A dimulai. Untuk T.A 2027/2028 → Jul 2026 …
     * Jun 2027.
     *
     * Sengaja BUKAN Jul 2027–Jun 2028 (tahun ajarannya sendiri): pendaftaran &
     * pembayaran registrasi terjadi SEBELUM tahun ajaran berjalan, sehingga
     * memakai rentang tahun ajaran membuat semua kolom bulan bernilai 0 padahal
     * totalnya berisi — persis yang terlihat saat dashboard ini pertama diuji.
     *
     * @return list<array{kunci:string,label:string}>
     */
    public function bulanTa(string $ta): array
    {
        $tahunAwal = preg_match('/^(\d{4})/', $ta, $m) ? (int) $m[1] : (int) date('Y');
        $mulai = Carbon::create($tahunAwal - 1, 7, 1);

        $hasil = [];
        for ($i = 0; $i < 12; $i++) {
            $bulan = $mulai->copy()->addMonths($i);
            $hasil[] = ['kunci' => $bulan->format('Y-m'), 'label' => $bulan->translatedFormat('M y')];
        }

        return $hasil;
    }

    /**
     * Tabel 1 — PENDAFTAR: santri yang registrasinya sudah dibayar, dipetakan ke
     * bulan pembayaran registrasi pertamanya (satu santri dihitung sekali).
     *
     * Tabel 2 — CLOSING: santri yang uang pangkalnya sudah mulai dibayar,
     * dipetakan ke bulan pembayaran uang pangkal pertamanya.
     *
     * @return array{bulan:list<array{kunci:string,label:string}>,baris:list<array<string,mixed>>,total:array<string,int>}
     */
    public function tabelBulanan(string $ta, string $tipe): array
    {
        $bulan = $this->bulanTa($ta);
        $pertama = $this->bulanPertamaBayar($ta, $tipe === 'pendaftar' ? ['registrasi'] : ['uang_pangkal']);
        $jenjangSantri = Santri::where('tahun_ajaran', $ta)->pluck('kode_jenjang', 'id')->all();

        $baris = [];
        $totalKolom = array_fill_keys(array_column($bulan, 'kunci'), 0);
        $totalKolom['luar'] = 0;
        $totalKolom['total'] = 0;

        foreach ($this->jenjangDipakai($ta) as $kode => $nama) {
            $sel = array_fill_keys(array_column($bulan, 'kunci'), 0);
            $luar = 0;
            $totalBaris = 0;
            foreach ($pertama as $idSantri => $kunciBulan) {
                if (($jenjangSantri[$idSantri] ?? null) !== $kode) {
                    continue;
                }
                // Pembayaran di luar 12 bulan musim penerimaan dikumpulkan di kolom
                // "Di luar rentang" — bukan dibuang diam-diam. Tanpa kolom itu,
                // jumlah kolom bulan bisa lebih kecil dari Total dan tabel terlihat
                // salah hitung.
                if (isset($sel[$kunciBulan])) {
                    $sel[$kunciBulan]++;
                    $totalKolom[$kunciBulan]++;
                } else {
                    $luar++;
                    $totalKolom['luar']++;
                }
                $totalBaris++;
                $totalKolom['total']++;
            }
            $baris[] = ['kode' => $kode, 'nama' => $nama, 'sel' => $sel, 'luar' => $luar, 'total' => $totalBaris];
        }

        return ['bulan' => $bulan, 'baris' => $baris, 'total' => $totalKolom, 'ada_luar' => $totalKolom['luar'] > 0];
    }

    /**
     * TREN bulanan satu tahun ajaran: **satu garis per JENJANG**, sumbu X bulan
     * ke-1..12 musim penerimaan (Juli→Juni).
     *
     * Sumbernya sengaja tabelBulanan() yang sama dengan tabel di bawah grafik —
     * grafik dan tabel tak mungkin menunjukkan angka berbeda.
     *
     * @return array{bulan:list<string>,seri:list<array{label:string,nilai:list<int>,total:int}>}
     */
    public function trenBulanan(string $tipe, string $ta): array
    {
        $label = ['Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
            'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni'];

        $tabel = $this->tabelBulanan($ta, $tipe);
        $urutBulan = array_column($tabel['bulan'], 'kunci');

        $seri = [];
        foreach ($tabel['baris'] as $baris) {
            $nilai = [];
            foreach ($urutBulan as $kunci) {
                $nilai[] = $baris['sel'][$kunci];
            }
            // Jenjang tanpa pendaftar tetap digambar sebagai garis datar di nol —
            // itu jawaban yang benar, bukan alasan menyembunyikan serinya.
            $seri[] = ['label' => $baris['kode'], 'nilai' => $nilai, 'total' => array_sum($nilai)];
        }

        return ['bulan' => $label, 'seri' => $seri];
    }

    /**
     * Bulan pembayaran PERTAMA tiap santri untuk tipe biaya tertentu.
     *
     * @param  list<string>  $tipe
     * @return array<int,string> [id_santri => 'Y-m']
     */
    private function bulanPertamaBayar(string $ta, array $tipe): array
    {
        $rows = PembayaranSantri::query()
            ->where('status', self::DIAKUI)
            ->whereHas('santri', fn ($q) => $q->where('tahun_ajaran', $ta))
            ->whereHas('tagihan.jenis', fn ($q) => $q->whereIn('tipe', TipeBiaya::kodeBerperilaku(...$tipe)))
            ->orderBy('tanggal')->orderBy('id')
            ->get(['id_santri', 'tanggal']);

        $hasil = [];
        foreach ($rows as $r) {
            // Sudah terurut tanggal → yang pertama ditemukan adalah yang terawal.
            $hasil[$r->id_santri] ??= Carbon::parse($r->tanggal)->format('Y-m');
        }

        return $hasil;
    }

    /** Id santri yang sudah mulai membayar uang pangkal (definisi CLOSING). */
    public function idClosing(string $ta): array
    {
        return array_keys($this->bulanPertamaBayar($ta, ['uang_pangkal']));
    }

    /**
     * 3 — Outstanding uang pangkal milik yang SUDAH closing. Yang sama sekali
     * belum membayar sengaja tak dihitung: itu bukan piutang yang sedang
     * berjalan, melainkan calon yang belum mengikat.
     *
     * @return array{total:string,jumlah_santri:int,per_jenjang:list<array<string,mixed>>}
     */
    public function outstandingClosing(string $ta): array
    {
        $ids = $this->idClosing($ta);
        if ($ids === []) {
            return ['total' => '0', 'jumlah_santri' => 0, 'per_jenjang' => []];
        }

        $rows = TagihanSantri::query()
            ->whereIn('id_santri', $ids)
            ->where('status', '!=', 'batal')
            ->whereHas('jenis', fn ($q) => $q->whereIn('tipe', TipeBiaya::kodeBerperilaku('uang_pangkal')))
            ->with('santri:id,kode_jenjang')
            ->get(['id', 'id_santri', 'sisa']);

        $total = '0';
        $perJenjang = [];
        $santriBersisa = [];
        foreach ($rows as $t) {
            if (Money::lte($t->sisa, '0')) {
                continue;
            }
            $kode = $t->santri?->kode_jenjang ?? '—';
            $perJenjang[$kode] ??= ['kode' => $kode, 'nominal' => '0', 'santri' => 0];
            $perJenjang[$kode]['nominal'] = Money::add($perJenjang[$kode]['nominal'], $t->sisa);
            $perJenjang[$kode]['santri']++;
            $santriBersisa[$t->id_santri] = true;
            $total = Money::add($total, $t->sisa);
        }
        ksort($perJenjang);

        return ['total' => $total, 'jumlah_santri' => count($santriBersisa), 'per_jenjang' => array_values($perJenjang)];
    }

    /**
     * 4 — Penerimaan yang sudah masuk: registrasi + uang pangkal + perlengkapan
     * (termasuk cicilan termin, karena semuanya tercatat sebagai pembayaran
     * tagihan yang sama).
     *
     * Perlengkapan dihitung di KOLOM SENDIRI, tidak dilebur ke uang pangkal:
     * kalau digabung, angkanya tak lagi bisa dibandingkan dengan tarif uang
     * pangkal mana pun di master.
     *
     * @return array{registrasi:string,uang_pangkal:string,perlengkapan:string,total:string,per_bulan:array<string,string>}
     */
    public function penerimaan(string $ta): array
    {
        $rows = PembayaranSantri::query()
            ->where('status', self::DIAKUI)
            ->whereHas('santri', fn ($q) => $q->where('tahun_ajaran', $ta))
            ->with('tagihan.jenis:kode,tipe')
            ->get(['id', 'id_tagihan', 'nominal', 'tanggal']);

        $registrasi = '0';
        $uangPangkal = '0';
        $perlengkapan = '0';
        $perBulan = [];
        foreach ($rows as $p) {
            $tipe = TipeBiaya::perilakuDari($p->tagihan?->jenis?->tipe);
            if ($tipe === 'registrasi') {
                $registrasi = Money::add($registrasi, $p->nominal);
            } elseif ($tipe === 'uang_pangkal') {
                $uangPangkal = Money::add($uangPangkal, $p->nominal);
            } elseif ($tipe === 'perlengkapan') {
                $perlengkapan = Money::add($perlengkapan, $p->nominal);
            } else {
                continue; // SPP & tagihan lain di luar lingkup PPSB
            }
            $kunci = Carbon::parse($p->tanggal)->format('Y-m');
            $perBulan[$kunci] = Money::add($perBulan[$kunci] ?? '0', $p->nominal);
        }

        return [
            'registrasi' => $registrasi,
            'uang_pangkal' => $uangPangkal,
            'perlengkapan' => $perlengkapan,
            'total' => Money::add(Money::add($registrasi, $uangPangkal), $perlengkapan),
            'per_bulan' => $perBulan,
        ];
    }

    /**
     * RINCIAN kartu penerimaan: siapa saja yang menyumbang angkanya. Dipakai
     * tombol "lihat detail" agar angka besar di kartu bisa ditelusuri sampai ke
     * santri — dan dari sana ke rekap pembayarannya.
     *
     * Penjumlahan dikerjakan DATABASE (SUM per santri), bukan dengan menarik
     * seluruh pembayaran ke memori lalu dijumlahkan PHP: begitu pendaftarnya
     * ratusan, cara lama memuat ribuan baris tiap kali panel dibuka.
     *
     * @param  'registrasi'|'uang_pangkal'|'perlengkapan'|'total'  $jenis
     */
    public function kueriPenerimaan(string $ta, string $jenis, string $cari = '')
    {
        $tipe = match ($jenis) {
            'registrasi' => ['registrasi'],
            'uang_pangkal' => ['uang_pangkal'],
            'perlengkapan' => ['perlengkapan'],
            default => ['registrasi', 'uang_pangkal', 'perlengkapan'],
        };

        // Kolom "registrasi", "uang pangkal", & "perlengkapan" dijumlahkan per
        // PERILAKU tipe, jadi tipe buatan sendiri ikut masuk kolom yang benar.
        // Daftar kodenya di-bind sebagai parameter, bukan ditempel ke SQL.
        $kodeReg = TipeBiaya::kodeBerperilaku('registrasi');
        $kodeUp = TipeBiaya::kodeBerperilaku('uang_pangkal');
        $kodePl = TipeBiaya::kodeBerperilaku('perlengkapan');
        $isi = fn (array $kode) => implode(',', array_fill(0, count($kode), '?'));

        return DB::table('pembayaran_santri as p')
            ->join('tagihan_santri as t', 't.id', '=', 'p.id_tagihan')
            ->join('jenis_biaya as j', 'j.kode', '=', 't.kode_jenis')
            ->join('santri as s', 's.id', '=', 'p.id_santri')
            ->where('p.status', self::DIAKUI)
            ->where('s.tahun_ajaran', $ta)
            ->whereIn('j.tipe', TipeBiaya::kodeBerperilaku(...$tipe))
            ->tap(fn ($q) => $this->saringCari($q, $cari))
            ->groupBy('s.id', 's.nama', 's.no_pendaftaran', 's.nis', 's.kode_jenjang', 's.jalur')
            ->select(['s.id', 's.nama', 's.no_pendaftaran', 's.nis', 's.kode_jenjang as jenjang', 's.jalur'])
            ->selectRaw("COALESCE(SUM(CASE WHEN j.tipe IN ({$isi($kodeReg)}) THEN p.nominal ELSE 0 END), 0) as registrasi", $kodeReg)
            ->selectRaw("COALESCE(SUM(CASE WHEN j.tipe IN ({$isi($kodeUp)}) THEN p.nominal ELSE 0 END), 0) as uang_pangkal", $kodeUp)
            ->selectRaw("COALESCE(SUM(CASE WHEN j.tipe IN ({$isi($kodePl)}) THEN p.nominal ELSE 0 END), 0) as perlengkapan", $kodePl)
            ->selectRaw('COALESCE(SUM(p.nominal), 0) as total')
            ->selectRaw('COUNT(*) as jumlah_bayar')
            ->selectRaw('MAX(p.tanggal) as terakhir')
            ->orderByDesc('total')->orderBy('s.nama');
    }

    /**
     * RINCIAN outstanding closing: santri yang sudah mulai membayar uang pangkal
     * tetapi masih bersisa. Yang belum membayar sama sekali tetap dikecualikan —
     * definisinya sama persis dengan kartunya, ditegakkan lewat whereExists
     * (bukan daftar id di PHP, yang ikut membengkak saat santrinya ratusan).
     */
    public function kueriOutstanding(string $ta, string $cari = '')
    {
        return DB::table('tagihan_santri as t')
            ->join('jenis_biaya as j', 'j.kode', '=', 't.kode_jenis')
            ->join('santri as s', 's.id', '=', 't.id_santri')
            ->whereIn('j.tipe', TipeBiaya::kodeBerperilaku('uang_pangkal'))
            ->where('t.status', '!=', 'batal')
            ->where('t.sisa', '>', 0)
            ->where('s.tahun_ajaran', $ta)
            ->whereExists(fn ($q) => $q->from('pembayaran_santri as p2')
                ->join('tagihan_santri as t2', 't2.id', '=', 'p2.id_tagihan')
                ->join('jenis_biaya as j2', 'j2.kode', '=', 't2.kode_jenis')
                ->whereColumn('p2.id_santri', 's.id')
                ->where('p2.status', self::DIAKUI)
                ->whereIn('j2.tipe', TipeBiaya::kodeBerperilaku('uang_pangkal'))
                ->selectRaw('1'))
            ->tap(fn ($q) => $this->saringCari($q, $cari))
            ->select([
                's.id', 's.nama', 's.no_pendaftaran', 's.nis',
                's.kode_jenjang as jenjang', 's.jalur',
                't.nominal', 't.sisa', 't.jatuh_tempo',
                DB::raw('(t.nominal - t.sisa) as terbayar'),
            ])
            ->orderByDesc('t.sisa')->orderBy('s.nama');
    }

    /** Pencarian bebas pada identitas santri: nomor pendaftaran, NIS, atau nama. */
    private function saringCari($query, string $cari): void
    {
        $cari = trim($cari);
        if ($cari === '') {
            return;
        }

        $query->where(fn ($w) => $w
            ->where('s.nama', 'ilike', "%{$cari}%")
            ->orWhere('s.no_pendaftaran', 'ilike', "%{$cari}%")
            ->orWhere('s.nis', 'ilike', "%{$cari}%"));
    }

    /**
     * Rincian siap tampil: terpaginasi agar daftar ratusan santri tak dimuat
     * sekaligus. `$semua` dipakai unduhan yang memang harus utuh.
     */
    public function rincian(string $ta, string $jenis, string $cari = '', bool $semua = false, int $perHalaman = 25)
    {
        $kueri = $jenis === 'outstanding'
            ? $this->kueriOutstanding($ta, $cari)
            : $this->kueriPenerimaan($ta, $jenis, $cari);

        return $semua ? $kueri->get() : $kueri->paginate($perHalaman)->withQueryString();
    }

    /**
     * 5 — Plan vs aktual per jenjang & jenis kelamin. Aktual = CLOSING (sudah
     * mulai membayar uang pangkal), bukan sekadar mendaftar.
     *
     * @return array{baris:list<array<string,mixed>>,total:array<string,mixed>}
     */
    public function planVsAktual(string $ta): array
    {
        $target = TargetSantri::where('tahun_ajaran', $ta)->get()->keyBy('kode_jenjang');
        $aktual = Santri::whereIn('id', $this->idClosing($ta))
            ->get(['id', 'kode_jenjang', 'jenis_kelamin'])
            ->groupBy('kode_jenjang');

        $kodeJenjang = collect($this->jenjangDipakai($ta))->keys()
            ->merge($target->keys())->unique()->values();

        $baris = [];
        $tot = ['target_l' => 0, 'target_p' => 0, 'target' => 0, 'aktual_l' => 0, 'aktual_p' => 0, 'aktual' => 0];
        foreach ($kodeJenjang as $kode) {
            $t = $target[$kode] ?? null;
            $rows = $aktual[$kode] ?? collect();
            $aktualL = $rows->where('jenis_kelamin', 'L')->count();
            $aktualP = $rows->where('jenis_kelamin', 'P')->count();

            $item = [
                'kode' => $kode,
                'nama' => Referensi::jenjang()[$kode] ?? $kode,
                'target_l' => $t?->target_l,
                'target_p' => $t?->target_p,
                'target' => (int) ($t?->target ?? 0),
                'aktual_l' => $aktualL,
                'aktual_p' => $aktualP,
                'aktual' => $rows->count(),
            ];
            $item['selisih'] = $item['aktual'] - $item['target'];
            $item['persen'] = $this->persen($item['aktual'], $item['target']);
            $item['persen_l'] = $this->persen($aktualL, $t?->target_l);
            $item['persen_p'] = $this->persen($aktualP, $t?->target_p);
            $baris[] = $item;

            $tot['target_l'] += (int) $t?->target_l;
            $tot['target_p'] += (int) $t?->target_p;
            $tot['target'] += $item['target'];
            $tot['aktual_l'] += $aktualL;
            $tot['aktual_p'] += $aktualP;
            $tot['aktual'] += $item['aktual'];
        }
        $tot['selisih'] = $tot['aktual'] - $tot['target'];
        $tot['persen'] = $this->persen($tot['aktual'], $tot['target']);
        $tot['persen_l'] = $this->persen($tot['aktual_l'], $tot['target_l']);
        $tot['persen_p'] = $this->persen($tot['aktual_p'], $tot['target_p']);

        return ['baris' => $baris, 'total' => $tot];
    }

    /** Pencapaian %; null bila targetnya belum diisi (bukan 0%, itu beda arti). */
    private function persen(int $aktual, ?int $target): ?float
    {
        return $target > 0 ? round($aktual / $target * 100, 1) : null;
    }

    /**
     * Sebaran per JALUR PENDAFTARAN: baris jalur × kolom (jenis kelamin × jenjang).
     *
     * $basis menentukan populasinya — 'closing' (sudah mulai membayar uang pangkal,
     * selaras dengan tabel plan vs aktual) atau 'pendaftar' (sudah membayar
     * registrasi). Keduanya tetap memakai aturan "pembayaran dulu, baru dihitung".
     *
     * @return array{jenjang:array<string,string>,baris:list<array<string,mixed>>,total:array<string,mixed>}
     */
    public function sebaranJalur(string $ta, string $basis = 'closing'): array
    {
        $ids = $basis === 'pendaftar'
            ? array_keys($this->bulanPertamaBayar($ta, ['registrasi']))
            : $this->idClosing($ta);

        $santri = Santri::whereIn('id', $ids)->get(['id', 'jalur', 'kode_jenjang', 'jenis_kelamin']);
        $jenjang = $this->jenjangDipakai($ta);

        // Baris = SELURUH jalur master (aktif) + jalur yang benar-benar dipakai,
        // supaya jalur yang belum ada pendaftarnya tetap terlihat sebagai 0
        // — itu informasi, bukan baris kosong yang layak disembunyikan.
        $jalur = \App\Models\JalurPendaftaran::where('status', 'aktif')->orderBy('kode')
            ->pluck('nama', 'kode')->all();
        foreach ($santri->pluck('jalur')->filter()->unique() as $kode) {
            $jalur[$kode] ??= $kode;
        }

        $kosong = fn () => ['L' => array_fill_keys(array_keys($jenjang), 0), 'P' => array_fill_keys(array_keys($jenjang), 0)];
        $total = $kosong();
        $totalBaris = ['L' => 0, 'P' => 0];

        $baris = [];
        foreach ($jalur as $kode => $nama) {
            $sel = $kosong();
            $jml = ['L' => 0, 'P' => 0];
            foreach ($santri->where('jalur', $kode) as $s) {
                $jk = $s->jenis_kelamin === 'P' ? 'P' : 'L';
                $kj = $s->kode_jenjang;
                if (! isset($sel[$jk][$kj])) {
                    continue; // jenjang di luar daftar (data lama) — abaikan di sel
                }
                $sel[$jk][$kj]++;
                $total[$jk][$kj]++;
                $jml[$jk]++;
                $totalBaris[$jk]++;
            }
            $baris[] = ['kode' => $kode, 'nama' => $nama, 'sel' => $sel, 'jumlah' => $jml, 'total' => $jml['L'] + $jml['P']];
        }

        return [
            'jenjang' => $jenjang,
            'baris' => $baris,
            'total' => ['sel' => $total, 'jumlah' => $totalBaris, 'total' => $totalBaris['L'] + $totalBaris['P']],
        ];
    }

    /**
     * 6 — Ranking sumber informasi. Memakai definisi PENDAFTAR yang sama
     * (sudah membayar registrasi) agar tak ada dua arti "pendaftar" di satu
     * halaman; "lainnya" menampilkan teks isian yang paling sering muncul.
     *
     * @return array{baris:list<array<string,mixed>>,total:int}
     */
    public function sumberInformasi(string $ta): array
    {
        $ids = array_keys($this->bulanPertamaBayar($ta, ['registrasi']));
        $rows = Santri::whereIn('id', $ids)->get(['id', 'sumber_informasi', 'sumber_informasi_lain']);

        // Label dari master (termasuk yang nonaktif) agar data lama tetap terbaca.
        $label = \App\Models\SumberInformasi::label();

        $hitung = [];
        $lain = [];
        foreach ($rows as $s) {
            $kunci = $s->sumber_informasi ?: 'tidak_diisi';
            $hitung[$kunci] = ($hitung[$kunci] ?? 0) + 1;
            if ($kunci === 'lainnya' && $s->sumber_informasi_lain) {
                $lain[$s->sumber_informasi_lain] = ($lain[$s->sumber_informasi_lain] ?? 0) + 1;
            }
        }
        arsort($hitung);
        arsort($lain);
        $total = array_sum($hitung);

        $baris = [];
        $peringkat = 0;
        foreach ($hitung as $kunci => $jumlah) {
            $baris[] = [
                'peringkat' => ++$peringkat,
                'kode' => $kunci,
                'nama' => $label[$kunci] ?? 'Tidak diisi',
                'jumlah' => $jumlah,
                'persen' => $total > 0 ? round($jumlah / $total * 100, 1) : 0,
                'rincian' => $kunci === 'lainnya' ? $lain : [],
            ];
        }

        return ['baris' => $baris, 'total' => $total];
    }

    /**
     * Jenjang yang perlu tampil: master jenjang aktif + jenjang yang benar-benar
     * dipakai santri T.A ini (supaya data lama tetap terlihat walau jenjangnya
     * sudah dinonaktifkan).
     *
     * @return array<string,string>
     */
    private function jenjangDipakai(string $ta): array
    {
        $master = Referensi::jenjang();
        $dipakai = Santri::where('tahun_ajaran', $ta)->whereNotNull('kode_jenjang')
            ->distinct()->pluck('kode_jenjang');

        foreach ($dipakai as $kode) {
            $master[$kode] ??= $kode;
        }

        return $master;
    }

    /**
     * Apakah setelan PPSB sudah siap untuk T.A ini (untuk pesan menuntun di view).
     * Dua-duanya diperlukan: baris akun di jenis biaya DAN sel tarifnya —
     * salah satu saja tak cukup untuk menerbitkan tagihan.
     */
    public function masterSiap(string $ta): bool
    {
        $adaAkun = JenisBiaya::whereIn('tipe', TipeBiaya::kodeBerperilaku('registrasi', 'uang_pangkal'))
            ->where('status', 'aktif')->exists();

        return $adaAkun && \App\Models\TarifBiaya::where('tahun_ajaran', $ta)
            ->whereIn('perilaku', ['registrasi', 'uang_pangkal'])->exists();
    }
}
