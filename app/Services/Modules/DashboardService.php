<?php

namespace App\Services\Modules;

use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JournalLine;
use App\Models\OpeningBalance;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Dashboard: agregasi ringkas dari buku besar (port dashboard.service.ts dev).
 * Semua status jurnal ikut dihitung (void + pembalik saling meniadakan).
 * Klasifikasi via prefix kode_coa: 1 Aset · 2 Liabilitas · 3 Ekuitas · 4 Pendapatan · 5 Beban.
 * 2.1 Hutang Jangka Pendek · 2.2 Jangka Panjang · 2.3 Hutang Pajak.
 */
class DashboardService
{
    private const HUTANG_PREFIX = ['pendek' => '2.1', 'panjang' => '2.2', 'pajak' => '2.3'];

    /** Baris jurnal + tanggal entry + unit (dari baris). */
    public function lines(): Collection
    {
        return JournalLine::query()
            ->join('journal_entries', 'journal_lines.entry_id', '=', 'journal_entries.id')
            ->get(['journal_lines.kode_coa', 'journal_lines.debet', 'journal_lines.kredit', 'journal_lines.kode_unit', 'journal_entries.tanggal']);
    }

    /** Saldo pembuka per akun dalam orientasi DEBET. @return array<string,string> */
    private function openingDebit(): array
    {
        $m = [];
        foreach (OpeningBalance::all(['kode_coa', 'jenis_saldo', 'saldo']) as $o) {
            $cur = $m[$o->kode_coa] ?? '0';
            $m[$o->kode_coa] = Money::add($cur, $o->jenis_saldo === 'debet' ? Money::of($o->saldo) : Money::mul($o->saldo, '-1'));
        }

        return $m;
    }

    /** Σdebet & Σkredit per akun (seluruh waktu). @return array<string,array{d:string,k:string}> */
    private function sumByAccount(): array
    {
        $rows = JournalLine::selectRaw('kode_coa, COALESCE(SUM(debet),0) as d, COALESCE(SUM(kredit),0) as k')
            ->groupBy('kode_coa')->get();
        $m = [];
        foreach ($rows as $r) {
            $m[$r->kode_coa] = ['d' => Money::of($r->d), 'k' => Money::of($r->k)];
        }

        return $m;
    }

    private function periodKey(string $mode, $tanggal): string
    {
        return $mode === 'bulanan' ? Carbon::parse($tanggal)->format('Y-m') : 'TOTAL';
    }

    // (a) Saldo kas & rekening + rincian
    public function kasRekening(): array
    {
        $opening = $this->openingDebit();
        $sums = $this->sumByAccount();
        $total = '0';
        $rincian = BankAccount::orderBy('kode_coa')->get()->map(function ($b) use ($opening, $sums, &$total) {
            $o = $opening[$b->kode_coa] ?? '0';
            $s = $sums[$b->kode_coa] ?? ['d' => '0', 'k' => '0'];
            $saldo = Money::sub(Money::add($o, $s['d']), $s['k']); // kas = aset (debet normal)
            $total = Money::add($total, $saldo);

            return [
                'kode_coa' => $b->kode_coa, 'nama' => $b->nama_rekening, 'jenis' => $b->jenis_rekening,
                'nama_bank' => $b->nama_bank ?? '', 'no_rekening' => $b->no_rekening ?? '', 'saldo' => $saldo,
            ];
        })->all();

        return ['total' => $total, 'rincian' => $rincian];
    }

    // (b/c/d) Hutang per jenis
    public function hutang(string $jenis): array
    {
        $prefix = self::HUTANG_PREFIX[$jenis] ?? '2.1';
        $opening = $this->openingDebit();
        $sums = $this->sumByAccount();
        $total = '0';
        $totalTambah = '0';
        $totalKurang = '0';
        $rincian = CoaDetail::where('kode_coa', 'like', $prefix.'%')->orderBy('kode_coa')->get()
            ->map(function ($a) use ($opening, $sums, &$total, &$totalTambah, &$totalKurang) {
                $o = $opening[$a->kode_coa] ?? '0';
                $s = $sums[$a->kode_coa] ?? ['d' => '0', 'k' => '0'];
                $saldoAwal = Money::mul($o, '-1');       // liabilitas = kredit normal
                $penambahan = $s['k'];                    // kredit menambah hutang
                $pengurangan = $s['d'];                   // debet mengurangi hutang
                $saldo = Money::sub(Money::add($saldoAwal, $penambahan), $pengurangan);
                $total = Money::add($total, $saldo);
                $totalTambah = Money::add($totalTambah, $penambahan);
                $totalKurang = Money::add($totalKurang, $pengurangan);

                return [
                    'kode_coa' => $a->kode_coa, 'nama_coa' => $a->nama_coa, 'saldo_awal' => $saldoAwal,
                    'penambahan' => $penambahan, 'pengurangan' => $pengurangan, 'saldo' => $saldo,
                ];
            })->all();

        return ['jenis' => $jenis, 'total' => $total, 'total_penambahan' => $totalTambah, 'total_pengurangan' => $totalKurang, 'rincian' => $rincian];
    }

    /** Bucket dua-mode (TOTAL + per-bulan) → ['total'=>[row],'bulanan'=>[rows]]. */
    private function twoMode(Collection $lines, callable $filter, callable $init, callable $fold, callable $finish): array
    {
        $buckets = []; // key => state
        foreach ($lines as $l) {
            $keyPart = $filter($l);
            if ($keyPart === null) {
                continue;
            }
            foreach (['TOTAL', Carbon::parse($l->tanggal)->format('Y-m')] as $periode) {
                $key = $keyPart.'|'.$periode;
                if (! isset($buckets[$key])) {
                    $buckets[$key] = $init($keyPart, $periode);
                }
                $buckets[$key] = $fold($buckets[$key], $l);
            }
        }
        $rows = array_map($finish, array_values($buckets));
        usort($rows, fn ($a, $b) => [$a['_sortU'] ?? '', $a['periode']] <=> [$b['_sortU'] ?? '', $b['periode']]);
        $strip = fn ($r) => array_diff_key($r, ['_sortU' => 1]);

        return [
            'total' => array_values(array_map($strip, array_filter($rows, fn ($r) => $r['periode'] === 'TOTAL'))),
            'bulanan' => array_values(array_map($strip, array_filter($rows, fn ($r) => $r['periode'] !== 'TOTAL'))),
        ];
    }

    // (e) Cash Flow
    public function cashFlow(Collection $lines, array $bankSet): array
    {
        return $this->twoMode(
            $lines,
            fn ($l) => isset($bankSet[$l->kode_coa]) ? 'x' : null,
            fn ($k, $p) => ['periode' => $p, 'masuk' => '0', 'keluar' => '0'],
            fn ($b, $l) => ['periode' => $b['periode'], 'masuk' => Money::add($b['masuk'], $l->debet), 'keluar' => Money::add($b['keluar'], $l->kredit)],
            fn ($b) => ['periode' => $b['periode'], 'masuk' => $b['masuk'], 'keluar' => $b['keluar'], 'net' => Money::sub($b['masuk'], $b['keluar'])],
        );
    }

    // (f) Cash Flow per unit
    public function cashFlowUnit(Collection $lines, array $bankSet, array $unitName): array
    {
        return $this->twoMode(
            $lines,
            fn ($l) => isset($bankSet[$l->kode_coa]) ? ($l->kode_unit ?? '-') : null,
            fn ($k, $p) => ['unit' => $k, 'periode' => $p, 'masuk' => '0', 'keluar' => '0'],
            fn ($b, $l) => ['unit' => $b['unit'], 'periode' => $b['periode'], 'masuk' => Money::add($b['masuk'], $l->debet), 'keluar' => Money::add($b['keluar'], $l->kredit)],
            fn ($b) => ['_sortU' => $b['unit'], 'kode_unit' => $b['unit'], 'nama_unit' => $b['unit'] === '-' ? '(Tanpa Unit)' : ($unitName[$b['unit']] ?? $b['unit']), 'periode' => $b['periode'], 'masuk' => $b['masuk'], 'keluar' => $b['keluar'], 'net' => Money::sub($b['masuk'], $b['keluar'])],
        );
    }

    // (g) Laba Rugi per unit
    public function labaRugiUnit(Collection $lines, array $unitName): array
    {
        return $this->twoMode(
            $lines,
            fn ($l) => in_array($l->kode_coa[0] ?? '', ['4', '5'], true) ? ($l->kode_unit ?? '-') : null,
            fn ($k, $p) => ['unit' => $k, 'periode' => $p, 'pendapatan' => '0', 'beban' => '0'],
            function ($b, $l) {
                if (($l->kode_coa[0] ?? '') === '4') {
                    $b['pendapatan'] = Money::sub(Money::add($b['pendapatan'], $l->kredit), $l->debet);
                } else {
                    $b['beban'] = Money::sub(Money::add($b['beban'], $l->debet), $l->kredit);
                }

                return $b;
            },
            fn ($b) => ['_sortU' => $b['unit'], 'kode_unit' => $b['unit'], 'nama_unit' => $b['unit'] === '-' ? '(Tanpa Unit)' : ($unitName[$b['unit']] ?? $b['unit']), 'periode' => $b['periode'], 'pendapatan' => $b['pendapatan'], 'beban' => $b['beban'], 'laba' => Money::sub($b['pendapatan'], $b['beban'])],
        );
    }

    // (i) Pencapaian pendapatan (piutang: pengakuan vs realisasi)
    public function pencapaian(Collection $lines): array
    {
        $grup = CoaGroup::where('nama_grup', 'ilike', '%Piutang%')->pluck('kode_grup')->all();
        $piutang = CoaDetail::whereIn('kode_grup', $grup)->pluck('kode_coa')->flip()->all();

        return $this->twoMode(
            $lines,
            fn ($l) => isset($piutang[$l->kode_coa]) ? 'x' : null,
            fn ($k, $p) => ['periode' => $p, 'pengakuan' => '0', 'realisasi' => '0'],
            fn ($b, $l) => ['periode' => $b['periode'], 'pengakuan' => Money::add($b['pengakuan'], $l->debet), 'realisasi' => Money::add($b['realisasi'], $l->kredit)],
            fn ($b) => ['periode' => $b['periode'], 'pengakuan' => $b['pengakuan'], 'realisasi' => $b['realisasi'], 'outstanding' => Money::sub($b['pengakuan'], $b['realisasi']), 'persen_realisasi' => Money::gtZero($b['pengakuan']) ? Money::mul(Money::div($b['realisasi'], $b['pengakuan'], 4), '100') : '0.00'],
        );
    }

    // (h) Outstanding approval
    public function approvals(): array
    {
        $sum = fn ($model) => (function ($rows) {
            $t = '0';
            foreach ($rows as $r) {
                $t = Money::add($t, Money::of($r->nominal ?? 0));
            }

            return ['count' => $rows->count(), 'nominal' => $t];
        })($model::where('status', 'pending')->get());

        $void = $sum(\App\Models\VoidApproval::class);
        $edit = $sum(\App\Models\EditApproval::class);
        $posting = $sum(\App\Models\PostingApproval::class);

        return ['void' => $void, 'edit' => $edit, 'posting' => $posting, 'total_count' => $void['count'] + $edit['count'] + $posting['count']];
    }

    /** Kartu headline 4 angka. */
    public function summary(): array
    {
        return [
            'saldo_kas' => $this->kasRekening()['total'],
            'hutang_pendek' => $this->hutang('pendek')['total'],
            'hutang_panjang' => $this->hutang('panjang')['total'],
            'hutang_pajak' => $this->hutang('pajak')['total'],
        ];
    }

    /** Peta bantu. */
    public function bankSet(): array
    {
        return BankAccount::pluck('kode_coa')->flip()->all();
    }

    public function unitName(): array
    {
        return BusinessUnit::pluck('nama_unit', 'kode_unit')->all();
    }
}
