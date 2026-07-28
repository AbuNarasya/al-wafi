<?php

namespace App\Services\Reports;

use App\Exceptions\AppException;
use App\Models\Asset;
use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CashIn;
use App\Models\CashOut;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\CompanySettings;
use App\Models\Inventory;
use App\Models\JournalEntry;
use App\Models\OpeningBalance;
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
        $accounts = CoaDetail::orderBy('kode_coa')->get(['kode_coa', 'nama_coa', 'kode_grup', 'jenis_saldo', 'status'])->all();

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

    /** Saldo pembuka per akun (orientasi debet). @return array<string,string> */
    private function openingDebitMap(): array
    {
        $m = [];
        foreach (OpeningBalance::all(['kode_coa', 'jenis_saldo', 'saldo']) as $o) {
            $v = $o->jenis_saldo === 'debet' ? Money::of($o->saldo) : Money::sub('0', $o->saldo);
            $m[$o->kode_coa] = Money::add($m[$o->kode_coa] ?? '0', $v);
        }

        return $m;
    }

    /** Mutasi (debet − kredit) per akun untuk entry pada rentang. @return array<string,string> */
    private function movementDebitMap(?string $gte, ?string $lte): array
    {
        $rows = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'jl.entry_id', '=', 'je.id')
            ->when($gte, fn ($q) => $q->where('je.tanggal', '>=', $gte))
            ->when($lte, fn ($q) => $q->where('je.tanggal', '<=', $lte))
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
        $opening = $this->openingDebitMap();
        $upToAsOf = $this->movementDebitMap(null, $asOf);

        $balNormal = fn ($a) => $this->applySign(Money::add($opening[$a->kode_coa] ?? '0', $upToAsOf[$a->kode_coa] ?? '0'), $a->jenis_saldo);
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

    // ---- Laba Rugi ----

    public function labaRugi(string $from, string $to): array
    {
        $ctx = $this->coaContext();
        $move = $this->movementDebitMap($from, $to);
        $periodNormal = fn ($a) => $this->applySign($move[$a->kode_coa] ?? '0', $a->jenis_saldo);
        $acctsOf = fn ($root) => array_values(array_filter($ctx['accounts'], fn ($a) => $this->rootOfAccount($ctx, $a) === $root));
        $skip = fn ($a, $nilai) => $this->roundedZero($nilai);

        $pendapatan = $this->groupAccounts($acctsOf('4'), $ctx, $periodNormal, $skip);
        $beban = $this->groupAccounts($acctsOf('5'), $ctx, $periodNormal, $skip);
        $laba = Money::sub($pendapatan['total'], $beban['total']);

        return [
            'from' => $from, 'to' => $to,
            'pendapatan' => ['title' => 'Pendapatan', 'groups' => $pendapatan['groups'], 'total' => Money::of($pendapatan['total'])],
            'beban' => ['title' => 'Beban', 'groups' => $beban['groups'], 'total' => Money::of($beban['total'])],
            'total_pendapatan' => Money::of($pendapatan['total']), 'total_beban' => Money::of($beban['total']), 'laba_rugi_bersih' => Money::of($laba),
        ];
    }

    // ---- Perubahan Modal ----

    public function perubahanModal(string $from, string $to): array
    {
        $ctx = $this->coaContext();
        $opening = $this->openingDebitMap();
        $dayBefore = Carbon::parse($from)->subDay()->toDateString();
        $upToAwal = $this->movementDebitMap(null, $dayBefore);
        $inPeriod = $this->movementDebitMap($from, $to);

        $awalNormal = fn ($a) => $this->applySign(Money::add($opening[$a->kode_coa] ?? '0', $upToAwal[$a->kode_coa] ?? '0'), $a->jenis_saldo);
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

    public function arusKas(string $from, string $to): array
    {
        $build = function ($records) {
            $map = [];
            $total = '0';
            foreach ($records as $r) {
                foreach ($r['details'] as $d) {
                    if (! isset($map[$d['kode_coa']])) {
                        $map[$d['kode_coa']] = ['kode_coa' => $d['kode_coa'], 'nama_coa' => $d['nama_coa'], 'total' => '0', 'transaksi' => []];
                    }
                    $map[$d['kode_coa']]['total'] = Money::add($map[$d['kode_coa']]['total'], $d['nominal']);
                    $total = Money::add($total, $d['nominal']);
                    $map[$d['kode_coa']]['transaksi'][] = ['tanggal' => $r['tanggal'], 'nomor' => $r['nomor_transaksi'], 'keterangan' => $d['keterangan'] ?? $r['keterangan'] ?? '', 'pihak' => $r['pihak'], 'unit' => $r['unit'], 'nominal' => Money::of($d['nominal'])];
                }
            }
            ksort($map);
            $groups = array_map(fn ($g) => ['kode_coa' => $g['kode_coa'], 'nama_coa' => $g['nama_coa'], 'total' => Money::of($g['total']), 'transaksi' => $g['transaksi']], array_values($map));

            return ['groups' => $groups, 'total' => $total];
        };

        $cashIns = CashIn::with(['details', 'customer', 'unit'])->where('status', 'aktif')->whereBetween('tanggal', [$from, $to])->get();
        $cashOuts = CashOut::with(['details', 'vendor', 'unit'])->where('status', 'aktif')->whereBetween('tanggal', [$from, $to])->get();

        $masuk = $build($cashIns->map(fn ($c) => [
            'tanggal' => $c->tanggal, 'nomor_transaksi' => $c->nomor_transaksi, 'keterangan' => $c->keterangan,
            'pihak' => $c->customer?->nama_customer ?? '', 'unit' => $c->unit?->nama_unit ?? $c->kode_unit,
            'details' => $c->details->map(fn ($d) => ['kode_coa' => $d->kode_coa, 'nama_coa' => $d->nama_coa, 'nominal' => $d->nominal, 'keterangan' => $d->keterangan])->all(),
        ])->all());
        $keluar = $build($cashOuts->map(fn ($c) => [
            'tanggal' => $c->tanggal, 'nomor_transaksi' => $c->nomor_transaksi, 'keterangan' => $c->keterangan,
            'pihak' => $c->vendor?->nama_vendor ?? '', 'unit' => $c->unit?->nama_unit ?? $c->kode_unit,
            'details' => $c->details->map(fn ($d) => ['kode_coa' => $d->kode_coa, 'nama_coa' => $d->nama_coa, 'nominal' => $d->nominal, 'keterangan' => $d->keterangan])->all(),
        ])->all());

        return [
            'from' => $from, 'to' => $to, 'kas_masuk' => $masuk['groups'], 'kas_keluar' => $keluar['groups'],
            'total_masuk' => Money::of($masuk['total']), 'total_keluar' => Money::of($keluar['total']), 'kas_bersih' => Money::sub($masuk['total'], $keluar['total']),
        ];
    }

    // ---- Buku Besar (satu akun) ----

    public function bukuBesar(string $kodeCoa, ?string $from = null, ?string $to = null): array
    {
        $from ??= '1900-01-01';
        $to ??= '9999-12-31';
        $akun = CoaDetail::find($kodeCoa);
        if (! $akun) {
            throw new AppException(404, 'Akun COA tidak ditemukan.');
        }
        $normal = $akun->jenis_saldo === 'debet';

        $saldoAwalDebit = '0';
        foreach (OpeningBalance::where('kode_coa', $kodeCoa)->get() as $o) {
            $saldoAwalDebit = Money::add($saldoAwalDebit, $o->jenis_saldo === 'debet' ? Money::of($o->saldo) : Money::sub('0', $o->saldo));
        }
        $before = DB::table('journal_lines as jl')->join('journal_entries as je', 'jl.entry_id', '=', 'je.id')
            ->where('jl.kode_coa', $kodeCoa)->where('je.tanggal', '<', $from)->selectRaw('COALESCE(SUM(jl.debet),0) as d, COALESCE(SUM(jl.kredit),0) as k')->first();
        $saldoAwalDebit = Money::sub(Money::add($saldoAwalDebit, $before->d), $before->k);

        $inRange = \App\Models\JournalLine::where('kode_coa', $kodeCoa)
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
            'periode' => ['from' => $from, 'to' => $to],
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
            $nilai = Money::mul($stok, $it->harga_perolehan);
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
