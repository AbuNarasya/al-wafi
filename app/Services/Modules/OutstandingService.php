<?php

namespace App\Services\Modules;

use App\Models\Accrue;
use App\Models\CashInDetail;
use App\Models\Invoice;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Kontrol Outstanding Account (port outstanding.service.ts) — READ-ONLY,
 * mengagregasi saldo outstanding lintas modul (tanpa jurnal):
 *  - Hutang Vendor  : Invoice.sisa_hutang > 0 (aging by jatuh tempo)
 *  - Uang Muka Ops  : OperationalAdvance sisa > 0
 *  - Uang Muka Cust : baris Kas Masuk jenis uang_muka yang belum diakui
 *  - Accrue         : Accrue status aktif
 */
class OutstandingService
{
    public function __construct(
        private OperationalAdvanceService $opAdvance = new OperationalAdvanceService,
        private CashInService $cashIn = new CashInService,
    ) {}

    private function daysBetween(Carbon $a, Carbon $b): int
    {
        return $b->startOfDay()->diffInDays($a->startOfDay(), false);
    }

    private function agingBucket(int $hariLewat): string
    {
        if ($hariLewat <= 0) return 'belum_jatuh_tempo';
        if ($hariLewat <= 30) return '1-30';
        if ($hariLewat <= 60) return '31-60';
        if ($hariLewat <= 90) return '61-90';

        return '>90';
    }

    /** Hutang vendor belum lunas + info aging per baris. */
    public function hutangVendor(): array
    {
        $now = Carbon::now();

        return Invoice::where('status', '!=', 'void')
            ->where('sisa_hutang', '>', 0)
            ->with(['vendor', 'unit'])
            ->orderBy('tanggal_jatuh_tempo')
            ->get()
            ->map(function ($r) use ($now) {
                $hariLewat = $this->daysBetween($now, $r->tanggal_jatuh_tempo);

                return [
                    'id_invoice' => $r->id_invoice,
                    'nomor_invoice' => $r->nomor_invoice,
                    'nomor_ref_internal' => $r->nomor_ref_internal,
                    'tanggal_invoice' => $r->tanggal_invoice,
                    'tanggal_jatuh_tempo' => $r->tanggal_jatuh_tempo,
                    'kode_vendor' => $r->kode_vendor,
                    'nama_vendor' => $r->vendor?->nama_vendor,
                    'kode_unit' => $r->kode_unit,
                    'nama_unit' => $r->unit?->nama_unit,
                    'total' => Money::of($r->total),
                    'sisa_hutang' => Money::of($r->sisa_hutang),
                    'status' => $r->status,
                    'hari_lewat' => $hariLewat,
                    'aging' => $this->agingBucket($hariLewat),
                ];
            })->all();
    }

    /** Uang muka operasional outstanding (sisa > 0). */
    public function uangMukaOperasional()
    {
        return $this->opAdvance->listOutstanding();
    }

    /** Uang muka customer (Kas Masuk jenis uang_muka) yang belum diakui penuh. */
    public function uangMukaCustomer(): array
    {
        $out = [];
        $lines = CashInDetail::where('jenis_kas_masuk', 'uang_muka')->with('cashIn.customer')->get();
        foreach ($lines as $d) {
            $ci = $d->cashIn;
            if (! $ci || $ci->status === 'void') {
                continue;
            }
            $diakui = $this->cashIn->recognizedAmount($ci->nomor_transaksi, $d);
            $sisa = Money::sub($d->nominal, $diakui);
            if (Money::gt($sisa, 0)) {
                $out[] = [
                    'detail_id' => $d->id,
                    'kode_transaksi' => $ci->kode_transaksi,
                    'nomor_transaksi' => $ci->nomor_transaksi,
                    'tanggal' => $ci->tanggal,
                    'kode_customer' => $ci->kode_customer,
                    'nama_customer' => optional($ci->customer)->nama_customer,
                    'kode_coa' => $d->kode_coa,
                    'nama_coa' => $d->nama_coa,
                    'nominal' => Money::of($d->nominal),
                    'nominal_diakui' => Money::of($diakui),
                    'sisa' => Money::of($sisa),
                ];
            }
        }

        return $out;
    }

    /** Accrue/Prepaid masih aktif. */
    public function accrue()
    {
        return Accrue::where('status', 'aktif')->orderByDesc('id_accrue')->get();
    }

    /** Ringkasan total per kategori (+ aging hutang vendor). */
    public function summary(): array
    {
        $hutang = $this->hutangVendor();
        $umOps = $this->uangMukaOperasional();
        $umCust = $this->uangMukaCustomer();
        $accrue = $this->accrue();

        $sumArr = fn ($arr, $field) => collect($arr)->reduce(fn ($acc, $r) => Money::add($acc, is_array($r) ? $r[$field] : $r->{$field}), '0');

        $aging = ['belum_jatuh_tempo' => '0', '1-30' => '0', '31-60' => '0', '61-90' => '0', '>90' => '0'];
        foreach ($hutang as $h) {
            $aging[$h['aging']] = Money::add($aging[$h['aging']], $h['sisa_hutang']);
        }

        return [
            'hutang_vendor' => ['jumlah' => count($hutang), 'total' => Money::of($sumArr($hutang, 'sisa_hutang')), 'aging' => array_map(fn ($v) => Money::of($v), $aging)],
            'uang_muka_operasional' => ['jumlah' => count($umOps), 'total' => Money::of($sumArr($umOps, 'sisa'))],
            'uang_muka_customer' => ['jumlah' => count($umCust), 'total' => Money::of($sumArr($umCust, 'sisa'))],
            'accrue' => ['jumlah' => count($accrue), 'total' => Money::of($sumArr($accrue, 'nominal'))],
        ];
    }
}
