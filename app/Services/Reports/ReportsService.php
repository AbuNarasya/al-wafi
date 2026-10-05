<?php

namespace App\Services\Reports;

use App\Exceptions\AppException;
use App\Models\Asset;
use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\CompanySettings;
use App\Models\Inventory;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Services\Modules\PeriodCloseService;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pelaporan keuangan. Semua saldo dihitung dalam orientasi DEBET (debet positif),
 * lalu dikalikan sign akun untuk disajikan normal. Saldo pembuka + mutasi jurnal;
 * void + pembalik saling meniadakan (semua status ikut dihitung).
 */
class ReportsService
{
    public const KELOMPOK_LABEL = ['1' => 'Aset', '2' => 'Liabilitas', '3' => 'Ekuitas', '4' => 'Pendapatan', '5' => 'Beban'];

    /**
     * Kelompok Laporan Arus Kas. `belum` BUKAN kategori akuntansi — ia wadah
     * bagi akun yang klasifikasinya belum diisi, sengaja ditampilkan terpisah
     * dan mencolok. Menyembunyikannya, atau menumpangkannya ke "operasi",
     * membuat laporan tampak lengkap padahal sebagian arusnya salah kamar.
     */
    public const KLASIFIKASI_ARUS = [
        'operasi' => 'Aktivitas Operasi',
        'investasi' => 'Aktivitas Investasi',
        'pendanaan' => 'Aktivitas Pendanaan',
        'belum' => 'Belum Diklasifikasikan',
    ];

    // ---- Helper COA ----

    /** @return array{accounts:array,namaGrup:array,rootOfGrup:array} */
    private function coaContext(): array
    {
        $groups = CoaGroup::all()->keyBy('kode_grup');
        $rootOfGrup = [];
        $rootOf = function (string $kodeGrup) use ($groups, &$rootOf, &$rootOfGrup) {
            if (isset($rootOfGrup[$kodeGrup])) {
                return $rootOfGrup[$kodeGrup];
            }
            $cur = $groups->get($kodeGrup);
            $seen = [];
            while ($cur && $cur->kode_induk && ! in_array($cur->kode_grup, $seen, true)) {
                $seen[] = $cur->kode_grup;
                $cur = $groups->get($cur->kode_induk);
            }

            return $rootOfGrup[$kodeGrup] = ($cur ? $cur->kode_grup : null);
        };
        foreach ($groups as $g) {
            $rootOf($g->kode_grup);
        }
        $accounts = CoaDetail::orderBy('kode_coa')
            ->get(['kode_coa', 'nama_coa', 'kode_grup', 'jenis_saldo', 'status', 'klasifikasi_arus_kas', 'sifat_pembatasan'])->all();

        return [
            'accounts' => $accounts,
            'namaGrup' => $groups->map(fn ($g) => $g->nama_grup)->all(),
            'rootOfGrup' => $rootOfGrup,
        ];
    }

    private function rootOfAccount(array $ctx, $a): ?string
    {
        return $ctx['rootOfGrup'][$a->kode_grup] ?? null;
    }

    /** +val untuk debet-normal, -val untuk kredit-normal. */
    private function applySign($val, string $jenisSaldo): string
    {
        return $jenisSaldo === 'debet' ? Money::of($val) : Money::sub('0', $val);
    }

    private function roundedZero($v): bool
    {
        return Money::isZero($v);
    }

    /*
     * SALDO AWAL DIBACA HANYA DARI BUKU BESAR.
     *
     * Dulu setiap laporan menjumlahkan tabel draf `opening_balances` DITAMBAH
     * seluruh jurnal — padahal sejak saldo awal difinalisasi, isinya juga sudah
     * ada sebagai jurnal pembuka (sumber `SaldoAwal`). Sesudah finalisasi angka
     * itu terhitung dua kali di neraca, neraca saldo, buku besar, arus kas, dan
     * perubahan modal. Kini jurnal pembuka bertanggal SEHARI SEBELUM periode
     * pembukuan (OpeningBalanceService::tanggalJurnal()), sehingga setiap
     * laporan yang dimulai di periode pertama membacanya sebagai saldo awal
     * lewat mutasi "sebelum tanggal awal" biasa. Draf yang belum difinalisasi
     * tidak muncul di laporan mana pun.
     */

    /**
     * Mutasi (debet − kredit) per akun untuk entry pada rentang.
     *
     * @param  ?string  $kodeUnit  saring per unit bisnis. Dimensi unit melekat di
     *                             BARIS jurnal (PostingService menyalinnya dari
     *                             kepala transaksi), jadi penyaringannya di jl,
     *                             bukan je.
     * @param  bool  $tanpaTutupBuku  kecualikan jurnal tutup buku tahunan (beserta
     *                                pembaliknya). WAJIB untuk laporan KINERJA:
     *                                jurnal itu memindahkan saldo pendapatan &
     *                                beban ke Laba Ditahan, bukan transaksi — dulu
     *                                ia membuat laba setahun penuh terbaca nol
     *                                sesudah tahunnya ditutup. Laporan POSISI
     *                                (neraca, neraca saldo) justru harus memuatnya.
     * @return array<string,string>
     */
    private function movementDebitMap(?string $gte, ?string $lte, ?string $kodeUnit = null, bool $tanpaTutupBuku = false): array
    {
        $rows = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'jl.entry_id', '=', 'je.id')
            ->when($gte, fn ($q) => $q->where('je.tanggal', '>=', $gte))
            ->when($lte, fn ($q) => $q->where('je.tanggal', '<=', $lte))
            ->when($kodeUnit, fn ($q) => $q->where('jl.kode_unit', $kodeUnit))
            // `sumber_modul` NOT NULL, jadi `!=` tak ikut membuang baris lain.
            ->when($tanpaTutupBuku, fn ($q) => $q->where('je.sumber_modul', '!=', PeriodCloseService::SUMBER))
            ->groupBy('jl.kode_coa')
            ->selectRaw('jl.kode_coa as kode_coa, SUM(jl.debet) as d, SUM(jl.kredit) as k')
            ->get();
        $m = [];
        foreach ($rows as $r) {
            $m[$r->kode_coa] = Money::sub($r->d, $r->k);
        }

        return $m;
    }

    private function fiscalYearStart(string $asOf): string
    {
        $s = CompanySettings::query()->value('periode_awal_pembukuan');

        return $s ? Carbon::parse($s)->toDateString() : Carbon::parse($asOf)->startOfYear()->toDateString();
    }

    /**
     * Kelompokkan akun per grup langsung (Level 3) + subtotal + total.
     *
     * @return array{groups:array,total:string}
     */
    private function groupAccounts(array $accts, array $ctx, callable $valueFn, callable $skipFn): array
    {
        $blocks = [];
        $order = [];
        $total = '0';
        foreach ($accts as $a) {
            $nilai = $valueFn($a);
            if ($skipFn($a, $nilai)) {
                continue;
            }
            if (! isset($blocks[$a->kode_grup])) {
                $blocks[$a->kode_grup] = ['kode_grup' => $a->kode_grup, 'nama_grup' => $ctx['namaGrup'][$a->kode_grup] ?? $a->kode_grup, '_sub' => '0', 'accounts' => []];
                $order[] = $a->kode_grup;
            }
            $blocks[$a->kode_grup]['accounts'][] = ['kode_coa' => $a->kode_coa, 'nama_coa' => $a->nama_coa, 'nilai' => Money::of($nilai)];
            $blocks[$a->kode_grup]['_sub'] = Money::add($blocks[$a->kode_grup]['_sub'], $nilai);
            $total = Money::add($total, $nilai);
        }
        $groups = array_map(fn ($k) => ['kode_grup' => $blocks[$k]['kode_grup'], 'nama_grup' => $blocks[$k]['nama_grup'], 'subtotal' => Money::of($blocks[$k]['_sub']), 'accounts' => $blocks[$k]['accounts']], $order);

        return ['groups' => $groups, 'total' => $total];
    }

    /** Laba berjalan = Σ pendapatan(4) − Σ beban(5). */
    private function labaBerjalan(array $ctx, callable $periodNormal): string
    {
        $pend = '0';
        $beban = '0';
        foreach ($ctx['accounts'] as $a) {
            $root = $this->rootOfAccount($ctx, $a);
            if ($root === '4') {
                $pend = Money::add($pend, $periodNormal($a));
            } elseif ($root === '5') {
                $beban = Money::add($beban, $periodNormal($a));
            }
        }

        return Money::sub($pend, $beban);
    }

    // ---- Neraca ----

    public function neraca(string $asOf): array
    {
        $ctx = $this->coaContext();
        $upToAsOf = $this->movementDebitMap(null, $asOf);

        $balNormal = fn ($a) => $this->applySign($upToAsOf[$a->kode_coa] ?? '0', $a->jenis_saldo);
        $acctsOf = fn ($root) => array_values(array_filter($ctx['accounts'], fn ($a) => $this->rootOfAccount($ctx, $a) === $root));
        $skip = fn ($a, $nilai) => $this->roundedZero($nilai) && $a->status === 'nonaktif';

        $aset = $this->groupAccounts($acctsOf('1'), $ctx, $balNormal, $skip);
        $liabilitas = $this->groupAccounts($acctsOf('2'), $ctx, $balNormal, $skip);
        $ekuitas = $this->groupAccounts($acctsOf('3'), $ctx, $balNormal, $skip);

        $fyStart = $this->fiscalYearStart($asOf);
        $inYear = $this->movementDebitMap($fyStart, $asOf);
        $laba = $this->labaBerjalan($ctx, fn ($a) => $this->applySign($inYear[$a->kode_coa] ?? '0', $a->jenis_saldo));

        $totalEkuitas = Money::add($ekuitas['total'], $laba);
        $balanced = $this->roundedZero(Money::sub($aset['total'], Money::add($liabilitas['total'], $totalEkuitas)));

        return [
            'asOf' => $asOf, 'fiscal_year_start' => $fyStart,
            'aset' => ['title' => 'Aset', 'groups' => $aset['groups'], 'total' => Money::of($aset['total'])],
            'liabilitas' => ['title' => 'Liabilitas', 'groups' => $liabilitas['groups'], 'total' => Money::of($liabilitas['total'])],
            'ekuitas' => ['title' => 'Ekuitas', 'groups' => $ekuitas['groups'], 'laba_berjalan' => Money::of($laba), 'total' => Money::of($totalEkuitas)],
            'total_aset' => Money::of($aset['total']), 'total_liabilitas' => Money::of($liabilitas['total']), 'total_ekuitas' => Money::of($totalEkuitas),
            'balanced' => $balanced,
        ];
    }

    // ---- Neraca Saldo ----

    /**
     * Neraca saldo (trial balance): saldo awal, mutasi debet & kredit, saldo
     * akhir — per akun, dalam bentuk dua kolom D/K seperti lazimnya.
     *
     * Gunanya BUKAN menyajikan posisi keuangan (itu tugas Neraca), melainkan
     * MEMBUKTIKAN bukunya seimbang dan menjadi titik tolak menelusuri selisih:
     * ketiga pasang total harus sama besar. Karena itu angka nolnya pun ikut
     * dihitung dan ketidakseimbangannya ditampilkan, bukan disembunyikan.
     *
     * @param  ?string  $kodeUnit  saring per unit bisnis. Saldo awal ikut sebatas
     *                             baris jurnal pembukanya yang berdimensi unit itu
     *                             (unit melekat per baris jurnal, termasuk jurnal
     *                             pembuka); tak ada lagi saldo dari tabel draf.
     *                             Konsekuensinya laporan per unit WAJAR bila tak
     *                             seimbang — `disaring_unit` dipakai halaman untuk
     *                             mengatakannya, bukan untuk menutupinya.
     */
    public function neracaSaldo(string $from, string $to, ?string $kodeUnit = null): array
    {
        $ctx = $this->coaContext();

        // Saldo awal = SELURUH mutasi sebelum tanggal `from`, termasuk jurnal
        // pembuka (bertanggal sehari sebelum periode pembukuan).
        $sebelum = Carbon::parse($from)->subDay()->toDateString();
        $awalMove = $this->movementDebitMap(null, $sebelum, $kodeUnit);
        $periode = $this->movementDebetKreditMap($from, $to, $kodeUnit);

        // Pecah satu nilai berorientasi debet jadi sepasang kolom D/K. Sisi yang
        // kosong tetap lewat Money::of() supaya berskala sama ('0.00', bukan '0')
        // — layar & unduhan membaca keduanya sebagai kolom angka yang sama.
        $sisi = fn ($net) => Money::isNegative($net)
            ? ['debet' => Money::of('0'), 'kredit' => Money::sub('0', $net)]
            : ['debet' => Money::of($net), 'kredit' => Money::of('0')];

        $rows = [];
        $total = [
            'awal_debet' => '0', 'awal_kredit' => '0',
            'mutasi_debet' => '0', 'mutasi_kredit' => '0',
            'akhir_debet' => '0', 'akhir_kredit' => '0',
        ];

        foreach ($ctx['accounts'] as $a) {
            $awal = Money::of($awalMove[$a->kode_coa] ?? '0');
            $mutD = $periode[$a->kode_coa]['debet'] ?? '0';
            $mutK = $periode[$a->kode_coa]['kredit'] ?? '0';
            $akhir = Money::add($awal, Money::sub($mutD, $mutK));

            // Akun yang sama sekali tak bergerak DAN tak bersaldo tidak dicetak:
            // daftar COA di sini panjang, dan barisnya yang kosong menenggelamkan
            // yang berisi. Akun bersaldo nol TAPI bermutasi tetap tampil — justru
            // di situ kesalahan pasangan debet/kredit biasanya bersembunyi.
            if ($this->roundedZero($awal) && $this->roundedZero($mutD)
                && $this->roundedZero($mutK) && $this->roundedZero($akhir)) {
                continue;
            }

            $sAwal = $sisi($awal);
            $sAkhir = $sisi($akhir);

            $rows[] = [
                'kode_coa' => $a->kode_coa,
                'nama_coa' => $a->nama_coa,
                'kelompok' => self::KELOMPOK_LABEL[$this->rootOfAccount($ctx, $a)] ?? '',
                'awal_debet' => $sAwal['debet'], 'awal_kredit' => $sAwal['kredit'],
                'mutasi_debet' => Money::of($mutD), 'mutasi_kredit' => Money::of($mutK),
                'akhir_debet' => $sAkhir['debet'], 'akhir_kredit' => $sAkhir['kredit'],
            ];

            $total['awal_debet'] = Money::add($total['awal_debet'], $sAwal['debet']);
            $total['awal_kredit'] = Money::add($total['awal_kredit'], $sAwal['kredit']);
            $total['mutasi_debet'] = Money::add($total['mutasi_debet'], $mutD);
            $total['mutasi_kredit'] = Money::add($total['mutasi_kredit'], $mutK);
            $total['akhir_debet'] = Money::add($total['akhir_debet'], $sAkhir['debet']);
            $total['akhir_kredit'] = Money::add($total['akhir_kredit'], $sAkhir['kredit']);
        }

        return [
            'from' => $from, 'to' => $to, 'kode_unit' => $kodeUnit,
            'rows' => $rows,
            'total' => array_map(fn ($v) => Money::of($v), $total),
            'seimbang_awal' => Money::eq($total['awal_debet'], $total['awal_kredit']),
            'seimbang_mutasi' => Money::eq($total['mutasi_debet'], $total['mutasi_kredit']),
            'seimbang_akhir' => Money::eq($total['akhir_debet'], $total['akhir_kredit']),
            'disaring_unit' => $kodeUnit !== null,
        ];
    }

    /**
     * Mutasi DEBET dan KREDIT terpisah per akun — beda dari movementDebitMap()
     * yang memampatkan keduanya jadi satu selisih. Neraca saldo justru harus
     * memperlihatkan kedua sisinya utuh.
     *
     * @return array<string,array{debet:string,kredit:string}>
     */
    private function movementDebetKreditMap(?string $gte, ?string $lte, ?string $kodeUnit = null): array
    {
        $rows = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'jl.entry_id', '=', 'je.id')
            ->when($gte, fn ($q) => $q->where('je.tanggal', '>=', $gte))
            ->when($lte, fn ($q) => $q->where('je.tanggal', '<=', $lte))
            ->when($kodeUnit, fn ($q) => $q->where('jl.kode_unit', $kodeUnit))
            ->groupBy('jl.kode_coa')
            ->selectRaw('jl.kode_coa as kode_coa, SUM(jl.debet) as d, SUM(jl.kredit) as k')
            ->get();

        $m = [];
        foreach ($rows as $r) {
            $m[$r->kode_coa] = ['debet' => Money::of($r->d), 'kredit' => Money::of($r->k)];
        }

        return $m;
    }

    // ---- Laba Rugi ----

    /**
     * Laba rugi periode. `$kodeUnit` menyaring per unit bisnis.
     *
     * CATATAN yang harus ikut ditampilkan halaman: baris jurnal yang unitnya
     * KOSONG tidak masuk laporan unit mana pun. Jadi menjumlahkan laba semua
     * unit belum tentu sama dengan laba keseluruhan — karena itu `tanpa_unit`
     * dihitung dan dipakai memperingatkan pembaca, bukan disembunyikan.
     */
    public function labaRugi(string $from, string $to, ?string $kodeUnit = null): array
    {
        $ctx = $this->coaContext();
        $move = $this->movementDebitMap($from, $to, $kodeUnit, tanpaTutupBuku: true);
        $periodNormal = fn ($a) => $this->applySign($move[$a->kode_coa] ?? '0', $a->jenis_saldo);
        $acctsOf = fn ($root) => array_values(array_filter($ctx['accounts'], fn ($a) => $this->rootOfAccount($ctx, $a) === $root));
        $skip = fn ($a, $nilai) => $this->roundedZero($nilai);

        $pendapatan = $this->groupAccounts($acctsOf('4'), $ctx, $periodNormal, $skip);
        $beban = $this->groupAccounts($acctsOf('5'), $ctx, $periodNormal, $skip);
        $laba = Money::sub($pendapatan['total'], $beban['total']);

        return [
            'from' => $from, 'to' => $to, 'kode_unit' => $kodeUnit,
            'pendapatan' => ['title' => 'Pendapatan', 'groups' => $pendapatan['groups'], 'total' => Money::of($pendapatan['total'])],
            'beban' => ['title' => 'Beban', 'groups' => $beban['groups'], 'total' => Money::of($beban['total'])],
            'total_pendapatan' => Money::of($pendapatan['total']), 'total_beban' => Money::of($beban['total']), 'laba_rugi_bersih' => Money::of($laba),
            'tanpa_unit' => $this->labaRugiTanpaUnit($ctx, $from, $to),
        ];
    }

    /**
     * Nilai mutasi akun Pendapatan & Beban pada periode yang barisnya TIDAK
     * berunit. Angka ini tidak muncul di laporan unit mana pun, jadi halaman
     * memakainya untuk memperingatkan bila ada yang tercecer.
     */
    private function labaRugiTanpaUnit(array $ctx, string $from, string $to): string
    {
        $kode = [];
        foreach ($ctx['accounts'] as $a) {
            if (in_array($this->rootOfAccount($ctx, $a), ['4', '5'], true)) {
                $kode[] = $a->kode_coa;
            }
        }
        if ($kode === []) {
            return '0';
        }

        $row = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'jl.entry_id', '=', 'je.id')
            ->whereBetween('je.tanggal', [$from, $to])
            ->where('je.sumber_modul', '!=', PeriodCloseService::SUMBER)
            ->whereNull('jl.kode_unit')
            ->whereIn('jl.kode_coa', $kode)
            ->selectRaw('COALESCE(SUM(jl.debet + jl.kredit), 0) as n')
            ->first();

        return Money::of($row->n ?? '0');
    }

    // ---- Perubahan Modal ----

    public function perubahanModal(string $from, string $to): array
    {
        $ctx = $this->coaContext();
        $dayBefore = Carbon::parse($from)->subDay()->toDateString();
        $upToAwal = $this->movementDebitMap(null, $dayBefore);
        $inPeriod = $this->movementDebitMap($from, $to);

        $awalNormal = fn ($a) => $this->applySign($upToAwal[$a->kode_coa] ?? '0', $a->jenis_saldo);
        $mutasiNormal = fn ($a) => $this->applySign($inPeriod[$a->kode_coa] ?? '0', $a->jenis_saldo);

        $rows = [];
        $totalAwal = '0';
        $totalMutasi = '0';
        foreach ($ctx['accounts'] as $a) {
            if ($this->rootOfAccount($ctx, $a) !== '3') {
                continue;
            }
            $awal = $awalNormal($a);
            $mutasi = $mutasiNormal($a);
            if ($this->roundedZero($awal) && $this->roundedZero($mutasi) && $a->status === 'nonaktif') {
                continue;
            }
            $totalAwal = Money::add($totalAwal, $awal);
            $totalMutasi = Money::add($totalMutasi, $mutasi);
            $rows[] = ['kode_coa' => $a->kode_coa, 'nama_coa' => $a->nama_coa, 'saldo_awal' => Money::of($awal), 'mutasi' => Money::of($mutasi), 'saldo_akhir' => Money::of(Money::add($awal, $mutasi))];
        }
        $totalSebelumLaba = Money::add($totalAwal, $totalMutasi);

        $fyStart = $this->fiscalYearStart($to);
        $inYear = $this->movementDebitMap($fyStart, $to);
        $laba = $this->labaBerjalan($ctx, fn ($a) => $this->applySign($inYear[$a->kode_coa] ?? '0', $a->jenis_saldo));

        return [
            'from' => $from, 'to' => $to, 'fiscal_year_start' => $fyStart, 'saldo_awal_label' => $dayBefore, 'rows' => $rows,
            'total_awal' => Money::of($totalAwal), 'total_mutasi' => Money::of($totalMutasi), 'total_sebelum_laba' => Money::of($totalSebelumLaba),
            'laba_berjalan' => Money::of($laba), 'total_ekuitas_akhir' => Money::of(Money::add($totalSebelumLaba, $laba)),
        ];
    }

    // ---- Arus Kas ----

    /**
     * LAPORAN ARUS KAS — dibangun dari `journal_lines`, bukan dari dokumen.
     *
     * Versi lama hanya membaca dokumen Kas Masuk & Kas Keluar. Akibatnya
     * SELURUH penerimaan santri tak pernah muncul: `PembayaranSantriService`
     * memposting jurnalnya sendiri dan tak pernah lewat Kas Masuk — padahal
     * itu sumber kas terbesar pesantren. Ikut hilang pula Pindah Buku,
     * pinjaman bank & karyawan, mutasi dompet, penyesuaian rekonsiliasi, dan
     * jurnal umum yang menyentuh kas. "Kas bersih"-nya karena itu tak pernah
     * sama dengan perubahan saldo kas di Neraca, dan tak ada yang memberi tahu.
     *
     * CARA KERJA. Akun kas dikenali dari tabel `bank_accounts` — sama seperti
     * yang dipakai PostingService, supaya tak ada daftar kedua yang bisa
     * menyimpang. Untuk tiap jurnal yang menyentuh kas, yang dibaca justru
     * baris NON-kasnya: tiap baris menyumbang (kredit − debet) ke arus kas,
     * dan akun baris itulah yang menentukan kelompoknya. Pembukuan berpasangan
     * menjamin jumlah seluruh sumbangan sama dengan pergerakan kasnya — dan
     * `selaras` membuktikannya di layar, bukan meminta pembaca percaya.
     *
     * Pindah buku antar rekening kas otomatis tak terhitung: kedua barisnya
     * akun kas, jadi tak menyisakan baris non-kas sama sekali.
     *
     * @param  ?string  $kodeUnit  saring per unit bisnis. Penyaringnya dikenakan
     *                             pada baris NON-kas — di situlah kegiatannya
     *                             berada, sedangkan baris kas kerap dipindahkan
     *                             ke unit penampung neraca oleh PostingService.
     *                             Konsekuensinya saldo kas awal/akhir TIDAK ikut
     *                             disaring, sehingga uji `selaras` sengaja
     *                             dimatikan saat menyaring unit.
     */
    public function arusKas(string $from, string $to, ?string $kodeUnit = null): array
    {
        $kasAkun = BankAccount::pluck('kode_coa')->all();
        if ($kasAkun === []) {
            return $this->arusKasKosong($from, $to, $kodeUnit);
        }

        // Baris non-kas dari jurnal yang menyentuh kas. (kredit − debet) = arus
        // masuk: akun yang dikredit (mis. pendapatan) menambah kas.
        $baris = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'jl.entry_id', '=', 'je.id')
            ->leftJoin('coa_detail as c', 'jl.kode_coa', '=', 'c.kode_coa')
            ->whereIn('jl.entry_id', function ($q) use ($kasAkun, $from, $to) {
                $q->select('jl2.entry_id')
                    ->from('journal_lines as jl2')
                    ->join('journal_entries as je2', 'jl2.entry_id', '=', 'je2.id')
                    ->whereIn('jl2.kode_coa', $kasAkun)
                    ->whereBetween('je2.tanggal', [$from, $to]);
            })
            ->whereBetween('je.tanggal', [$from, $to])
            ->whereNotIn('jl.kode_coa', $kasAkun)
            ->when($kodeUnit, fn ($q) => $q->where('jl.kode_unit', $kodeUnit))
            ->groupBy('jl.kode_coa', 'c.nama_coa', 'c.klasifikasi_arus_kas')
            ->selectRaw('jl.kode_coa as kode_coa, c.nama_coa as nama_coa,
                         c.klasifikasi_arus_kas as klasifikasi,
                         SUM(jl.kredit) - SUM(jl.debet) as arus')
            ->get();

        $kelompok = [];
        foreach (self::KLASIFIKASI_ARUS as $kunci => $label) {
            $kelompok[$kunci] = ['kunci' => $kunci, 'label' => $label, 'baris' => [], 'total' => '0'];
        }

        $bersih = '0';
        foreach ($baris as $b) {
            $arus = Money::of($b->arus);
            if ($this->roundedZero($arus)) {
                continue;
            }
            $kunci = $b->klasifikasi ?: 'belum';
            $kelompok[$kunci]['baris'][] = [
                'kode_coa' => $b->kode_coa,
                'nama_coa' => $b->nama_coa ?? $b->kode_coa,
                'arus' => $arus,
            ];
            $kelompok[$kunci]['total'] = Money::add($kelompok[$kunci]['total'], $arus);
            $bersih = Money::add($bersih, $arus);
        }

        foreach ($kelompok as $k => $v) {
            usort($kelompok[$k]['baris'], fn ($a, $b) => $a['kode_coa'] <=> $b['kode_coa']);
            $kelompok[$k]['total'] = Money::of($v['total']);
        }

        $saldoAwal = $this->saldoKas($kasAkun, null, Carbon::parse($from)->subDay()->toDateString());
        $saldoAkhir = $this->saldoKas($kasAkun, null, $to);

        return [
            'from' => $from, 'to' => $to, 'kode_unit' => $kodeUnit,
            'kelompok' => array_values($kelompok),
            'arus_bersih' => Money::of($bersih),
            'saldo_kas_awal' => Money::of($saldoAwal),
            'saldo_kas_akhir' => Money::of($saldoAkhir),
            // Uji-diri: saldo awal + arus bersih HARUS sama dengan saldo akhir.
            'saldo_kas_hitung' => Money::add($saldoAwal, $bersih),
            'selaras' => $kodeUnit === null && Money::eq(Money::add($saldoAwal, $bersih), $saldoAkhir),
            'disaring_unit' => $kodeUnit !== null,
            'jembatan' => $this->jembatanLabaKeKas($from, $to, $kodeUnit, $kasAkun, $kelompok['operasi']['total']),
        ];
    }

    /**
     * JEMBATAN LABA → KAS. Menjawab pertanyaan yang paling sering diajukan
     * pengurus: "kenapa laporannya surplus tapi kasnya menipis?"
     *
     * Jawabannya hampir selalu piutang: tagihan santri diakui sebagai
     * pendapatan sejak TERBIT, sedangkan uangnya menyusul — kadang tak pernah.
     * Selisih antara laba dan kas itulah yang dirinci di sini.
     *
     * Baris terakhir adalah selisih penyeimbang, sehingga jembatannya SELALU
     * bertemu dengan arus kas operasi. Makin lengkap klasifikasi akun neraca,
     * makin kecil baris itu dan makin tajam rinciannya.
     */
    private function jembatanLabaKeKas(string $from, string $to, ?string $kodeUnit, array $kasAkun, string $arusOperasi): array
    {
        $ctx = $this->coaContext();
        $laba = $this->labaRugi($from, $to, $kodeUnit)['laba_rugi_bersih'];

        // Penyusutan: beban yang tak pernah mengeluarkan kas.
        $penyusutan = Money::of(
            DB::table('journal_lines as jl')
                ->join('journal_entries as je', 'jl.entry_id', '=', 'je.id')
                ->where('je.sumber_modul', 'Depresiasi')
                ->whereBetween('je.tanggal', [$from, $to])
                ->when($kodeUnit, fn ($q) => $q->where('jl.kode_unit', $kodeUnit))
                ->sum('jl.debet')
        );

        // Akun neraca berklasifikasi operasi: piutang & persediaan di sisi aset,
        // hutang & titipan di sisi liabilitas. Akun kas dikecualikan — ia yang
        // sedang dijelaskan, bukan penjelasnya.
        $akunOperasi = fn (string $akar) => array_values(array_diff(
            array_map(fn ($a) => $a->kode_coa, array_filter(
                $ctx['accounts'],
                fn ($a) => $this->rootOfAccount($ctx, $a) === $akar && $a->klasifikasi_arus_kas === 'operasi',
            )),
            $kasAkun,
        ));

        $gerak = function (array $akun) use ($from, $to, $kodeUnit) {
            if ($akun === []) {
                return '0';
            }

            return Money::of(
                DB::table('journal_lines as jl')
                    ->join('journal_entries as je', 'jl.entry_id', '=', 'je.id')
                    ->whereIn('jl.kode_coa', $akun)
                    ->whereBetween('je.tanggal', [$from, $to])
                    ->when($kodeUnit, fn ($q) => $q->where('jl.kode_unit', $kodeUnit))
                    ->selectRaw('COALESCE(SUM(jl.debet) - SUM(jl.kredit), 0) as v')
                    ->value('v')
            );
        };

        // Aset naik → kas turun; liabilitas naik → kas naik.
        $aset = Money::sub('0', $gerak($akunOperasi('1')));
        $liabilitas = Money::sub('0', $gerak($akunOperasi('2')));

        $dijelaskan = Money::add(Money::add($laba, $penyusutan), Money::add($aset, $liabilitas));
        $lain = Money::sub($arusOperasi, $dijelaskan);

        return [
            'baris' => [
                ['label' => 'Laba/Rugi Bersih periode ini', 'nilai' => Money::of($laba), 'tebal' => false],
                ['label' => 'Penyusutan & amortisasi (beban tanpa kas)', 'nilai' => $penyusutan, 'tebal' => false],
                ['label' => '(Kenaikan)/Penurunan aset operasi — piutang, persediaan, uang muka', 'nilai' => $aset, 'tebal' => false],
                ['label' => 'Kenaikan/(Penurunan) liabilitas operasi — hutang, titipan', 'nilai' => $liabilitas, 'tebal' => false],
                ['label' => 'Penyesuaian lain (selisih penyeimbang)', 'nilai' => $lain, 'tebal' => false],
                ['label' => 'Arus Kas dari Aktivitas Operasi', 'nilai' => Money::of($arusOperasi), 'tebal' => true],
            ],
            // Dipakai layar untuk mengajak melengkapi klasifikasi: selisih
            // penyeimbang yang besar berarti rinciannya masih tumpul.
            'selisih_penyeimbang' => $lain,
        ];
    }

    /**
     * Saldo gabungan akun kas pada satu tanggal (kas = saldo normal debet).
     * Saldo awal kas ikut lewat jurnal pembuka (bertanggal sebelum periode
     * pertama), jadi di Arus Kas ia tampil sebagai kas awal, bukan kas masuk.
     */
    private function saldoKas(array $kasAkun, ?string $gte, ?string $lte): string
    {
        $mutasi = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'jl.entry_id', '=', 'je.id')
            ->whereIn('jl.kode_coa', $kasAkun)
            ->when($gte, fn ($q) => $q->where('je.tanggal', '>=', $gte))
            ->when($lte, fn ($q) => $q->where('je.tanggal', '<=', $lte))
            ->selectRaw('COALESCE(SUM(jl.debet) - SUM(jl.kredit), 0) as v')
            ->value('v');

        return Money::of($mutasi);
    }

    /** Belum ada satu pun rekening kas terdaftar — laporannya tak punya dasar. */
    private function arusKasKosong(string $from, string $to, ?string $kodeUnit): array
    {
        $kelompok = [];
        foreach (self::KLASIFIKASI_ARUS as $kunci => $label) {
            $kelompok[] = ['kunci' => $kunci, 'label' => $label, 'baris' => [], 'total' => Money::of('0')];
        }

        return [
            'from' => $from, 'to' => $to, 'kode_unit' => $kodeUnit,
            'kelompok' => $kelompok, 'arus_bersih' => Money::of('0'),
            'saldo_kas_awal' => Money::of('0'), 'saldo_kas_akhir' => Money::of('0'),
            'saldo_kas_hitung' => Money::of('0'), 'selaras' => true,
            'disaring_unit' => $kodeUnit !== null, 'jembatan' => null,
            'tanpa_rekening_kas' => true,
        ];
    }

    // ---- Laporan Perubahan Aset Neto (ISAK 35) ----

    /**
     * LAPORAN PERUBAHAN ASET NETO — laporan paling khas entitas nirlaba, dan
     * satu-satunya yang tak punya padanan di format perusahaan.
     *
     * Ia memisahkan aset neto DENGAN pembatasan dari yang TANPA pembatasan,
     * lalu memperlihatkan perpindahan di antara keduanya. Neraca & Laba Rugi
     * hanya menjawab "berapa"; laporan ini menjawab "berapa yang boleh dipakai
     * bebas" — pertanyaan yang sesungguhnya dihadapi pengurus yayasan.
     *
     * PELEPASAN PEMBATASAN dihitung dari belanja dana terikat pada periode itu.
     * Dasarnya: pembatasan gugur justru ketika dananya dipakai sesuai
     * peruntukannya. Karena tiap belanja dana sudah bertanda `kode_dana` (lihat
     * [[App\Services\Ledger\DanaPolicy]]), angkanya tak perlu diketik siapa pun
     * — ia turunan dari jurnal yang sudah ada.
     *
     * Beban seluruhnya masuk kolom TANPA pembatasan; itulah sebabnya pelepasan
     * pembatasan harus ada, kalau tidak kolom tanpa-pembatasan akan tampak
     * menanggung belanja yang sebetulnya dibiayai dana terikat.
     */
    public function perubahanAsetNeto(string $from, string $to): array
    {
        $ctx = $this->coaContext();
        $sebelum = Carbon::parse($from)->subDay()->toDateString();

        // Aset neto AWAL memuat tutup buku tahun-tahun lalu (laba sudah pindah
        // ke ekuitas); pendapatan & beban PERIODE tidak (lihat movementDebitMap).
        $sampaiAwal = $this->movementDebitMap(null, $sebelum);
        $periode = $this->movementDebitMap($from, $to, tanpaTutupBuku: true);

        $sifat = fn ($a) => $a->sifat_pembatasan === 'dengan_pembatasan' ? 'dengan' : 'tanpa';
        $akarOf = fn ($a) => $this->rootOfAccount($ctx, $a);

        // 1. Aset neto awal — saldo akun ekuitas sebelum periode, per sifat.
        $awal = ['tanpa' => '0', 'dengan' => '0'];
        foreach ($ctx['accounts'] as $a) {
            if ($akarOf($a) !== '3') {
                continue;
            }
            $saldo = $this->applySign($sampaiAwal[$a->kode_coa] ?? '0', $a->jenis_saldo);
            $awal[$sifat($a)] = Money::add($awal[$sifat($a)], $saldo);
        }

        // 2. Pendapatan periode, per sifat pembatasan akunnya.
        $pendapatan = ['tanpa' => '0', 'dengan' => '0'];
        $beban = '0';
        $rincianPendapatan = ['tanpa' => [], 'dengan' => []];
        $rincianBeban = [];

        foreach ($ctx['accounts'] as $a) {
            $akar = $akarOf($a);
            if ($akar !== '4' && $akar !== '5') {
                continue;
            }
            $nilai = $this->applySign($periode[$a->kode_coa] ?? '0', $a->jenis_saldo);
            if ($this->roundedZero($nilai)) {
                continue;
            }

            if ($akar === '4') {
                $k = $sifat($a);
                $pendapatan[$k] = Money::add($pendapatan[$k], $nilai);
                $rincianPendapatan[$k][] = ['kode_coa' => $a->kode_coa, 'nama_coa' => $a->nama_coa, 'nilai' => Money::of($nilai)];
            } else {
                // Beban SELURUHNYA ke kolom tanpa pembatasan — lihat catatan
                // metode ini. Pelepasan pembatasan yang menyeimbangkannya.
                $beban = Money::add($beban, $nilai);
                $rincianBeban[] = ['kode_coa' => $a->kode_coa, 'nama_coa' => $a->nama_coa, 'nilai' => Money::of($nilai)];
            }
        }

        $pelepasan = $this->pelepasanPembatasan($from, $to);

        // 3. Perubahan bersih per kolom.
        $naikTanpa = Money::add(Money::sub($pendapatan['tanpa'], $beban), $pelepasan);
        $naikDengan = Money::sub($pendapatan['dengan'], $pelepasan);

        $akhir = [
            'tanpa' => Money::add($awal['tanpa'], $naikTanpa),
            'dengan' => Money::add($awal['dengan'], $naikDengan),
        ];

        $jml = fn (array $k) => Money::add($k['tanpa'], $k['dengan']);

        return [
            'from' => $from, 'to' => $to,
            'awal' => array_map(fn ($v) => Money::of($v), $awal + ['jumlah' => $jml($awal)]),
            'pendapatan' => array_map(fn ($v) => Money::of($v), $pendapatan + ['jumlah' => $jml($pendapatan)]),
            'beban' => Money::of($beban),
            'pelepasan' => Money::of($pelepasan),
            'kenaikan' => [
                'tanpa' => Money::of($naikTanpa),
                'dengan' => Money::of($naikDengan),
                'jumlah' => Money::of(Money::add($naikTanpa, $naikDengan)),
            ],
            'akhir' => array_map(fn ($v) => Money::of($v), $akhir + ['jumlah' => $jml($akhir)]),
            'rincian_pendapatan' => $rincianPendapatan,
            'rincian_beban' => $rincianBeban,
            // Dipakai layar untuk mengajak menandai akun: selama tak ada satu
            // pun akun berpembatasan, laporan ini hanya berisi satu kolom dan
            // tak lebih berguna dari Laba Rugi biasa.
            'ada_pembatasan' => collect($ctx['accounts'])
                ->contains(fn ($a) => $a->sifat_pembatasan === 'dengan_pembatasan'),
        ];
    }

    /**
     * Pelepasan pembatasan periode ini = belanja yang dibiayai DANA TERIKAT.
     *
     * Pembatasan gugur justru ketika dananya terpakai sesuai peruntukannya,
     * jadi angkanya tak perlu diketik siapa pun — ia turunan dari baris beban
     * bertanda dana terikat yang sudah ada di jurnal.
     */
    private function pelepasanPembatasan(string $from, string $to): string
    {
        $terikat = DB::table('dana')->where('jenis', '!=', 'tidak_terikat')->pluck('kode_dana')->all();
        if ($terikat === []) {
            return '0';
        }

        $rows = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'jl.entry_id', '=', 'je.id')
            ->join('coa_detail as c', 'jl.kode_coa', '=', 'c.kode_coa')
            ->whereIn('jl.kode_dana', $terikat)
            ->whereBetween('je.tanggal', [$from, $to])
            ->groupBy('c.kode_coa', 'c.kode_grup')
            ->selectRaw('c.kode_coa as kode_coa, c.kode_grup as kode_grup, SUM(jl.debet) - SUM(jl.kredit) as v')
            ->get();

        $total = '0';
        foreach ($rows as $r) {
            if (CoaDetail::akarKelompok($r->kode_grup) === '5') {
                $total = Money::add($total, $r->v);
            }
        }

        return $total;
    }

    // ---- Buku Besar (satu akun) ----

    /**
     * @param  ?string  $kodeUnit  saring per unit bisnis (drill-down dari Laba
     *                             Rugi per unit). Saldo awal ikut lewat jurnal
     *                             pembukanya, sebatas baris yang berdimensi unit itu.
     */
    public function bukuBesar(string $kodeCoa, ?string $from = null, ?string $to = null, ?string $kodeUnit = null): array
    {
        $from ??= '1900-01-01';
        $to ??= '9999-12-31';
        $akun = CoaDetail::find($kodeCoa);
        if (! $akun) {
            throw new AppException(404, 'Akun COA tidak ditemukan.');
        }
        $normal = $akun->jenis_saldo === 'debet';

        // Saldo awal = seluruh mutasi sebelum `from`, termasuk jurnal pembuka.
        $before = DB::table('journal_lines as jl')->join('journal_entries as je', 'jl.entry_id', '=', 'je.id')
            ->where('jl.kode_coa', $kodeCoa)->where('je.tanggal', '<', $from)
            ->when($kodeUnit, fn ($q) => $q->where('jl.kode_unit', $kodeUnit))
            ->selectRaw('COALESCE(SUM(jl.debet),0) as d, COALESCE(SUM(jl.kredit),0) as k')->first();
        $saldoAwalDebit = Money::sub($before->d, $before->k);

        $inRange = JournalLine::where('kode_coa', $kodeCoa)
            ->when($kodeUnit, fn ($q) => $q->where('journal_lines.kode_unit', $kodeUnit))
            ->whereHas('entry', fn ($q) => $q->whereBetween('tanggal', [$from, $to]))
            ->with(['entry:id,tanggal,referensi,keterangan,status,id_pengguna'])
            ->join('journal_entries', 'journal_lines.entry_id', '=', 'journal_entries.id')
            ->orderBy('journal_entries.tanggal')->orderBy('journal_lines.id')
            ->select('journal_lines.*')->get();

        $running = $saldoAwalDebit;
        $mutasi = $inRange->map(function ($l) use (&$running, $normal) {
            $running = Money::sub(Money::add($running, $l->debet), $l->kredit);

            return [
                'tanggal' => $l->entry->tanggal, 'referensi' => $l->entry->referensi,
                'keterangan' => $l->keterangan ?? $l->entry->keterangan, 'unit_bisnis' => $l->kode_unit,
                'status' => $l->entry->status, 'debet' => Money::of($l->debet), 'kredit' => Money::of($l->kredit),
                'saldo' => $normal ? Money::of($running) : Money::sub('0', $running),
            ];
        })->all();

        return [
            'akun' => ['kode_coa' => $akun->kode_coa, 'nama_coa' => $akun->nama_coa, 'jenis_saldo' => $akun->jenis_saldo],
            'periode' => ['from' => $from, 'to' => $to], 'kode_unit' => $kodeUnit,
            'saldo_awal' => $normal ? Money::of($saldoAwalDebit) : Money::sub('0', $saldoAwalDebit),
            'mutasi' => $mutasi,
            'saldo_akhir' => $normal ? Money::of($running) : Money::sub('0', $running),
        ];
    }

    // ---- Laporan Aset & Persediaan ----

    private function calcMonthlyDepreciation(Asset $a): string
    {
        $bookValue = Money::sub($a->harga_perolehan, $a->akumulasi_depresiasi);
        if ($a->metode_depresiasi === 'garis_lurus') {
            $basis = Money::sub($a->harga_perolehan, $a->nilai_residu);

            return $a->umur_manfaat > 0 ? Money::div($basis, (string) $a->umur_manfaat) : '0';
        }
        $rate = $a->umur_manfaat > 0 ? Money::div('2', (string) $a->umur_manfaat, 6) : '0';

        return Money::mul($bookValue, $rate);
    }

    public function laporanAset(): array
    {
        $rows = [];
        $totalPerolehan = '0';
        $totalAkum = '0';
        $totalBuku = '0';
        foreach (Asset::orderBy('kode_aset')->get() as $a) {
            $buku = Money::sub($a->harga_perolehan, $a->akumulasi_depresiasi);
            $est = $a->status === 'aktif' ? $this->calcMonthlyDepreciation($a) : '0';
            $totalPerolehan = Money::add($totalPerolehan, $a->harga_perolehan);
            $totalAkum = Money::add($totalAkum, $a->akumulasi_depresiasi);
            $totalBuku = Money::add($totalBuku, $buku);
            $rows[] = ['kode_aset' => $a->kode_aset, 'nama_aset' => $a->nama_aset, 'kategori_aset' => $a->kategori_aset ?? '', 'harga_perolehan' => Money::of($a->harga_perolehan), 'akumulasi_depresiasi' => Money::of($a->akumulasi_depresiasi), 'nilai_buku' => Money::of($buku), 'depresiasi_bulanan' => Money::of($est), 'status' => $a->status];
        }

        return ['rows' => $rows, 'total_perolehan' => Money::of($totalPerolehan), 'total_akumulasi' => Money::of($totalAkum), 'total_nilai_buku' => Money::of($totalBuku)];
    }

    public function laporanPersediaan(): array
    {
        $rows = [];
        $totalNilai = '0';
        foreach (Inventory::orderBy('kode_persediaan')->get() as $it) {
            $stok = Money::sub($it->stok_masuk, $it->stok_keluar, 4);
            // Nilai diambil dari kolom turunan yang dijumlahkan PERSIS dari
            // lapisan FIFO — bukan stok × harga rata-rata. Harga rata-rata sudah
            // dibulatkan dua desimal, dan perkaliannya meleset beberapa rupiah,
            // cukup untuk membuat rekonsiliasi berteriak tanpa sebab.
            $nilai = Money::of($it->nilai_persediaan);
            $totalNilai = Money::add($totalNilai, $nilai);
            $rows[] = ['kode_persediaan' => $it->kode_persediaan, 'nama_persediaan' => $it->nama_persediaan, 'satuan' => $it->satuan ?? '', 'stok_masuk' => Money::of($it->stok_masuk, 4), 'stok_keluar' => Money::of($it->stok_keluar, 4), 'stok' => $stok, 'harga_perolehan' => Money::of($it->harga_perolehan), 'nilai_total' => Money::of($nilai)];
        }

        return ['rows' => $rows, 'total_nilai' => Money::of($totalNilai)];
    }

    // ---- Jurnal Mentah (export) ----

    public function jurnalMentah(?string $from = null, ?string $to = null, ?string $kodeUnit = null): array
    {
        $bankMap = BankAccount::pluck('nama_rekening', 'kode_coa');
        $unitMap = BusinessUnit::pluck('nama_unit', 'kode_unit');

        $entries = JournalEntry::with('lines')
            ->when($from, fn ($q) => $q->where('tanggal', '>=', $from))
            ->when($to, fn ($q) => $q->where('tanggal', '<=', $to))
            ->when($kodeUnit, fn ($q) => $q->whereHas('lines', fn ($l) => $l->where('kode_unit', $kodeUnit)))
            ->orderBy('tanggal')->orderBy('id')->get();

        $rows = [];
        foreach ($entries as $e) {
            $bankLines = $e->lines->filter(fn ($l) => $bankMap->has($l->kode_coa));
            $jenis = $bankLines->isNotEmpty() ? 'Kas' : 'Non-Kas';
            $rekening = $bankLines->map(fn ($l) => $bankMap[$l->kode_coa] ?? $l->kode_coa)->unique()->implode(', ');
            $lines = $e->lines->sortByDesc(fn ($l) => Money::gtZero($l->debet) ? 1 : 0);
            foreach ($lines as $l) {
                $rows[] = [
                    'tanggal' => $e->tanggal, 'referensi' => $e->referensi, 'kode_coa' => $l->kode_coa, 'nama_coa' => $l->nama_coa ?? '',
                    'jenis_transaksi' => $jenis, 'keterangan' => $l->keterangan ?? $e->keterangan ?? '', 'rekening' => $rekening,
                    'unit_bisnis' => $l->kode_unit ? ($unitMap[$l->kode_unit] ?? $l->kode_unit) : '', 'status' => $e->status,
                    'debet' => Money::of($l->debet), 'kredit' => Money::of($l->kredit),
                ];
            }
        }

        return ['from' => $from, 'to' => $to, 'rows' => $rows];
    }
}
