<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\Bagian;
use App\Models\Budget;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JournalLine;
use App\Services\Ledger\AnggaranPeriode;
use App\Services\Ledger\BagianPolicy;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Anggaran per akun/bulan (port budget.service.ts).
 *
 * Penyimpanan tetap KALENDER (Budget.tahun/bulan); Tahun Anggaran (TA) hanya
 * lapisan pemetaan 12 slot lewat AnggaranPeriode. Hanya akun Beban (kelompok 5)
 * yang boleh dianggarkan. Realisasi dihitung dari mutasi jurnal (non-kas
 * dikecualikan). Uang → Money (BCMath), tidak pernah float.
 */
class BudgetService
{
    private const KELOMPOK_LABEL = [
        '1' => 'Aset', '2' => 'Liabilitas', '3' => 'Ekuitas', '4' => 'Pendapatan', '5' => 'Beban',
    ];

    public function __construct(private BudgetLockService $lock = new BudgetLockService) {}

    /** +1 debet-normal, -1 kredit-normal. */
    private function sign(string $jenisSaldo): int
    {
        return $jenisSaldo === 'debet' ? 1 : -1;
    }

    /**
     * Peta akun + resolver kelompok root ("1".."5") dari kode_grup via telusur induk.
     *
     * @return array{acctMap: array<string,array{nama_coa:string,jenis_saldo:string,kode_grup:string}>, rootOf: \Closure}
     */
    private function loadRootResolver(): array
    {
        $groups = CoaGroup::all(['kode_grup', 'kode_induk'])->keyBy('kode_grup');
        $acctMap = [];
        foreach (CoaDetail::all(['kode_coa', 'nama_coa', 'jenis_saldo', 'kode_grup']) as $a) {
            $acctMap[$a->kode_coa] = [
                'nama_coa' => $a->nama_coa,
                'jenis_saldo' => $a->jenis_saldo,
                'kode_grup' => $a->kode_grup,
            ];
        }

        $rootOf = function (string $kodeGrup) use ($groups): ?string {
            $cur = $groups->get($kodeGrup);
            $seen = [];
            while ($cur && $cur->kode_induk && ! isset($seen[$cur->kode_grup])) {
                $seen[$cur->kode_grup] = true;
                $cur = $groups->get($cur->kode_induk);
            }

            return $cur ? $cur->kode_grup : null;
        };

        return ['acctMap' => $acctMap, 'rootOf' => $rootOf];
    }

    /**
     * Hanya akun Beban (kelompok 5) yang boleh dianggarkan. Baris nominal ≤ 0
     * (penghapusan) dilewati.
     *
     * @param  array<int,array{kode_coa:string,nominal:string}>  $items
     */
    public function assertItemsBeban(array $items): void
    {
        ['acctMap' => $acctMap, 'rootOf' => $rootOf] = $this->loadRootResolver();
        foreach ($items as $it) {
            if (Money::lte($it['nominal'], 0)) {
                continue;
            }
            $acct = $acctMap[$it['kode_coa']] ?? null;
            if (! $acct) {
                throw new AppException(400, "Akun {$it['kode_coa']} tidak ditemukan.");
            }
            if ($rootOf($acct['kode_grup']) !== BagianPolicy::KELOMPOK_ANGGARAN) {
                throw new AppException(
                    422,
                    "Akun {$it['kode_coa']} ({$acct['nama_coa']}) bukan akun Beban. Anggaran hanya untuk akun kelompok 5 (Beban).",
                );
            }
        }
    }

    /**
     * Grid anggaran untuk (TAHUN ANGGARAN, bagian, scope unit). kode_unit
     * ""/null = scope "semua unit". Per akun: 12 nilai berurutan menurut TA
     * (slot 0 = bulan awal TA). kode_bagian WAJIB.
     */
    public function grid(int $tahun, string $kodeBagian, ?string $kodeUnit = null): array
    {
        if ($kodeBagian === '') {
            throw new AppException(400, 'Bagian wajib dipilih.');
        }
        $scope = ($kodeUnit !== null && $kodeUnit !== '') ? $kodeUnit : null;
        $bulanAwal = AnggaranPeriode::bulanAwalAnggaran();
        $pairs = AnggaranPeriode::bulanTahunAnggaran($tahun, $bulanAwal);

        $q = Budget::where('kode_bagian', $kodeBagian)
            ->where(function ($w) use ($pairs) {
                foreach ($pairs as $p) {
                    $w->orWhere(fn ($qq) => $qq->where('tahun', $p['tahun'])->where('bulan', $p['bulan']));
                }
            });
        $scope === null ? $q->whereNull('kode_unit') : $q->where('kode_unit', $scope);
        $rows = $q->get();

        $names = CoaDetail::pluck('nama_coa', 'kode_coa');

        $perAkun = [];
        foreach ($rows as $r) {
            $slot = AnggaranPeriode::slotDalamTA($tahun, $bulanAwal, $r->tahun, $r->bulan);
            if ($slot === null) {
                continue;
            }
            if (! isset($perAkun[$r->kode_coa])) {
                $perAkun[$r->kode_coa] = [
                    'kode_coa' => $r->kode_coa,
                    'nama_coa' => $names[$r->kode_coa] ?? $r->kode_coa,
                    'bulanan' => array_fill(0, 12, '0'),
                ];
            }
            $perAkun[$r->kode_coa]['bulanan'][$slot] = Money::of($r->nominal);
        }

        ksort($perAkun);

        return [
            'tahun' => $tahun,
            'bulan_awal' => $bulanAwal,
            'label_ta' => AnggaranPeriode::labelTahunAnggaran($tahun, $bulanAwal),
            'bulan_urut' => $pairs,
            'terkunci' => $this->lock->isTerkunci($tahun),
            'kode_bagian' => $kodeBagian,
            'kode_unit' => $scope,
            'rows' => array_values($perAkun),
        ];
    }

    /**
     * Simpan massal anggaran satu scope (TA + bagian + unit). nominal 0 → hapus.
     * `tahun` = label TA; `it.bulan` = SLOT 1..12, diterjemahkan ke (tahun,bulan)
     * kalender untuk penyimpanan.
     *
     * @param  array{tahun:int,kode_bagian:string,kode_unit:?string,items:array<int,array{kode_coa:string,bulan:int,nominal:string}>}  $input
     */
    public function save(array $input): array
    {
        $scope = (! empty($input['kode_unit'])) ? $input['kode_unit'] : null;

        // TA terkunci = beku bagi SIAPA PUN (termasuk admin jalur ini).
        $this->lock->assertTidakTerkunci($input['tahun']);

        if (! Bagian::whereKey($input['kode_bagian'])->exists()) {
            throw new AppException(400, 'Bagian tidak ditemukan.');
        }

        // Hanya akun Beban (kelompok 5) yang boleh dianggarkan.
        $this->assertItemsBeban($input['items']);

        $bulanAwal = AnggaranPeriode::bulanAwalAnggaran();
        $pairs = AnggaranPeriode::bulanTahunAnggaran($input['tahun'], $bulanAwal);

        DB::transaction(function () use ($input, $pairs, $scope) {
            foreach ($input['items'] as $it) {
                $pair = $pairs[$it['bulan'] - 1] ?? null; // slot 1..12
                if (! $pair) {
                    throw new AppException(400, "Slot bulan {$it['bulan']} di luar 1..12.");
                }
                $q = Budget::where('tahun', $pair['tahun'])
                    ->where('bulan', $pair['bulan'])
                    ->where('kode_coa', $it['kode_coa'])
                    ->where('kode_bagian', $input['kode_bagian']);
                $scope === null ? $q->whereNull('kode_unit') : $q->where('kode_unit', $scope);
                $existing = $q->first();

                if (Money::lte($it['nominal'], 0)) {
                    $existing?->delete();

                    continue;
                }
                if ($existing) {
                    $existing->update(['nominal' => $it['nominal']]);
                } else {
                    Budget::create([
                        'tahun' => $pair['tahun'],
                        'bulan' => $pair['bulan'],
                        'kode_coa' => $it['kode_coa'],
                        'kode_bagian' => $input['kode_bagian'],
                        'kode_unit' => $scope,
                        'nominal' => $it['nominal'],
                    ]);
                }
            }
        });

        return ['ok' => true];
    }

    /**
     * Anggaran vs realisasi per akun per bulan + varians (realisasi − anggaran).
     * kode_bagian: string (satu), array (subtree), atau null (semua bagian).
     * Realisasi = mutasi jurnal berorientasi normal akun; non-kas dikecualikan.
     *
     * @param  string|array<int,string>|null  $kodeBagian
     */
    public function realisasi(int $tahun, string|array|null $kodeBagian = null, ?string $kodeUnit = null): array
    {
        $unit = (! empty($kodeUnit)) ? $kodeUnit : null;
        $bagianLabel = is_array($kodeBagian) ? null : $kodeBagian;

        ['acctMap' => $acctMap, 'rootOf' => $rootOf] = $this->loadRootResolver();
        $isBeban = function (string $kode) use ($acctMap, $rootOf): bool {
            $a = $acctMap[$kode] ?? null;

            return $a ? $rootOf($a['kode_grup']) === BagianPolicy::KELOMPOK_ANGGARAN : false;
        };

        $bulanAwal = AnggaranPeriode::bulanAwalAnggaran();
        $pairs = AnggaranPeriode::bulanTahunAnggaran($tahun, $bulanAwal);
        $slotOf = fn (int $y, int $m) => AnggaranPeriode::slotDalamTA($tahun, $bulanAwal, $y, $m);

        $applyBagian = function ($q, string $col) use ($kodeBagian) {
            if ($kodeBagian === null) {
                return;
            }
            is_array($kodeBagian) ? $q->whereIn($col, $kodeBagian) : $q->where($col, $kodeBagian);
        };

        // Anggaran (hanya akun Beban), difilter bagian/unit.
        $bq = Budget::where(function ($w) use ($pairs) {
            foreach ($pairs as $p) {
                $w->orWhere(fn ($qq) => $qq->where('tahun', $p['tahun'])->where('bulan', $p['bulan']));
            }
        });
        $applyBagian($bq, 'kode_bagian');
        if ($unit) {
            $bq->where('kode_unit', $unit);
        }
        $budgets = $bq->get()->filter(fn ($b) => $isBeban($b->kode_coa));

        // Anggaran per akun per SLOT TA (agregasi bila lintas unit).
        $anggaran = []; // kode_coa => [12 string]
        foreach ($budgets as $b) {
            $slot = $slotOf($b->tahun, $b->bulan);
            if ($slot === null) {
                continue;
            }
            if (! isset($anggaran[$b->kode_coa])) {
                $anggaran[$b->kode_coa] = array_fill(0, 12, '0');
            }
            $anggaran[$b->kode_coa][$slot] = Money::add($anggaran[$b->kode_coa][$slot], $b->nominal);
        }
        $codes = array_keys($anggaran);
        if (count($codes) === 0) {
            return [
                'tahun' => $tahun, 'bulan_awal' => $bulanAwal,
                'label_ta' => AnggaranPeriode::labelTahunAnggaran($tahun, $bulanAwal),
                'bulan_urut' => $pairs, 'kode_bagian' => $bagianLabel, 'kode_unit' => $unit,
                'rows' => [], 'total' => ['anggaran' => '0.00', 'realisasi' => '0.00', 'varians' => '0.00'],
            ];
        }

        // Realisasi: mutasi jurnal per akun per SLOT, sepanjang rentang TA.
        // Dimensi bagian & unit melekat di BARIS (konversi Laravel); rentang
        // tanggal & pengecualian non-kas ada di entry.
        $from = AnggaranPeriode::awalTahunAnggaran($tahun, $bulanAwal);
        $to = AnggaranPeriode::akhirBulan($pairs[11]['tahun'], $pairs[11]['bulan']);
        $lineQ = JournalLine::whereIn('kode_coa', $codes)
            ->whereHas('entry', fn ($q) => $q
                ->whereBetween('tanggal', [$from, $to])
                ->whereNotIn('sumber_modul', BagianPolicy::SUMBER_NON_KAS))
            ->with('entry:id,tanggal');
        $applyBagian($lineQ, 'kode_bagian');
        if ($unit) {
            $lineQ->where('kode_unit', $unit);
        }
        $lines = $lineQ->get(['kode_coa', 'debet', 'kredit', 'entry_id']);

        $realisasiDebit = []; // kode_coa => [12 string]  (debet − kredit, belum ×sign)
        foreach ($lines as $l) {
            $tgl = $l->entry->tanggal;
            $slot = $slotOf((int) $tgl->format('Y'), (int) $tgl->format('n'));
            if ($slot === null) {
                continue;
            }
            if (! isset($realisasiDebit[$l->kode_coa])) {
                $realisasiDebit[$l->kode_coa] = array_fill(0, 12, '0');
            }
            $net = Money::sub($l->debet, $l->kredit);
            $realisasiDebit[$l->kode_coa][$slot] = Money::add($realisasiDebit[$l->kode_coa][$slot], $net);
        }

        $gAng = '0';
        $gReal = '0';
        $rows = [];
        foreach ($codes as $kode) {
            $acct = $acctMap[$kode] ?? null;
            $s = $acct ? $this->sign($acct['jenis_saldo']) : 1;
            $root = $acct ? $rootOf($acct['kode_grup']) : null;
            $angArr = $anggaran[$kode];
            $realArr = $realisasiDebit[$kode] ?? array_fill(0, 12, '0');
            $totAng = '0';
            $totReal = '0';
            $bulanan = [];
            for ($i = 0; $i < 12; $i++) {
                $ang = $angArr[$i];
                $real = Money::mul($realArr[$i], $s); // orientasi normal
                $totAng = Money::add($totAng, $ang);
                $totReal = Money::add($totReal, $real);
                $bulanan[] = [
                    'bulan' => $pairs[$i]['bulan'],
                    'tahun' => $pairs[$i]['tahun'],
                    'anggaran' => Money::of($ang),
                    'realisasi' => Money::of($real),
                    'varians' => Money::sub($real, $ang),
                ];
            }
            $gAng = Money::add($gAng, $totAng);
            $gReal = Money::add($gReal, $totReal);
            $rows[] = [
                'kode_coa' => $kode,
                'nama_coa' => $acct['nama_coa'] ?? $kode,
                'kelompok' => $root ?? '',
                'kelompok_label' => $root ? (self::KELOMPOK_LABEL[$root] ?? '') : '',
                'bulanan' => $bulanan,
                'total_anggaran' => Money::of($totAng),
                'total_realisasi' => Money::of($totReal),
                'total_varians' => Money::sub($totReal, $totAng),
            ];
        }
        usort($rows, fn ($a, $b) => strcmp($a['kode_coa'], $b['kode_coa']));

        return [
            'tahun' => $tahun,
            'bulan_awal' => $bulanAwal,
            'label_ta' => AnggaranPeriode::labelTahunAnggaran($tahun, $bulanAwal),
            'bulan_urut' => $pairs,
            'kode_bagian' => $bagianLabel,
            'kode_unit' => $unit,
            'rows' => $rows,
            'total' => ['anggaran' => Money::of($gAng), 'realisasi' => Money::of($gReal), 'varians' => Money::sub($gReal, $gAng)],
        ];
    }
}
