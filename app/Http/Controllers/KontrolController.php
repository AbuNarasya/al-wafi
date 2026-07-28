<?php

namespace App\Http\Controllers;

use App\Services\Modules\OutstandingService;
use App\Support\Export\Exporter;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Kontrol Outstanding (READ-ONLY): ringkasan, aging AP (hutang vendor), uang
 * muka customer, uang muka operasional, accrue & prepaid. Port KontrolPages.tsx.
 */
class KontrolController extends Controller
{
    public function __construct(private OutstandingService $service = new OutstandingService) {}

    public function ringkasan(): View
    {
        return view('kontrol.ringkasan', ['s' => $this->service->summary()]);
    }

    public function agingAp(): View
    {
        return view('kontrol.aging-ap', ['rows' => $this->service->hutangVendor()]);
    }

    public function uangMukaCustomer(): View
    {
        return view('kontrol.uang-muka-customer', [
            'rows' => $this->service->uangMukaCustomer(),
            'coaOptions' => ['' => '— pilih akun Pendapatan —'] + \App\Models\CoaDetail::where('status', 'aktif')->orderBy('kode_coa')
                ->get()->mapWithKeys(fn ($c) => [$c->kode_coa => "{$c->kode_coa} — {$c->nama_coa}"])->all(),
            'bolehAkui' => \App\Support\Akses::boleh('cash-in', 'buat'),
        ]);
    }

    public function uangMukaOperasional(): View
    {
        return view('kontrol.uang-muka-operasional', ['rows' => $this->service->uangMukaOperasional()]);
    }

    public function accruePrepaid(): View
    {
        return view('kontrol.accrue-prepaid', ['rows' => $this->service->accrue()]);
    }

    public function rekapPembiayaan(): View
    {
        return view('kontrol.rekap-pembiayaan', ['rows' => (new \App\Services\Modules\BankLoanService)->rekap()]);
    }

    /** Unduh tabel kontrol (CSV/XLSX/PDF). */
    public function download(Request $request, string $type)
    {
        $fmt = $request->query('format', 'csv');
        $g = fn ($r, $k, $d = '') => data_get($r, $k, $d);
        $tgl = fn ($v) => $v ? Carbon::parse($v)->format('d/m/Y') : '';
        $aging = ['belum_jatuh_tempo' => 'Belum jatuh tempo', '1-30' => '1–30 hari', '31-60' => '31–60 hari', '61-90' => '61–90 hari', '>90' => '> 90 hari'];

        [$rows, $file, $title] = match ($type) {
            'aging-ap' => [collect($this->service->hutangVendor())->map(fn ($r) => [
                'No. Invoice' => $g($r, 'nomor_invoice'), 'Vendor' => $g($r, 'nama_vendor', $g($r, 'kode_vendor')),
                'Unit' => $g($r, 'nama_unit', $g($r, 'kode_unit')), 'Jatuh Tempo' => $tgl($g($r, 'tanggal_jatuh_tempo')),
                'Umur (hari)' => $g($r, 'hari_lewat'), 'Sisa Hutang' => $g($r, 'sisa_hutang'),
                'Aging' => $aging[$g($r, 'aging')] ?? $g($r, 'aging'),
            ])->all(), 'aging_ap', 'Aging AP — Hutang Vendor'],
            'uang-muka-customer' => [collect($this->service->uangMukaCustomer())->map(fn ($r) => [
                'No. Voucher' => $g($r, 'nomor_transaksi'), 'Tanggal' => $tgl($g($r, 'tanggal')),
                'Customer' => $g($r, 'nama_customer', $g($r, 'kode_customer')), 'Akun' => $g($r, 'kode_coa').' — '.$g($r, 'nama_coa'),
                'Nominal' => $g($r, 'nominal'), 'Diakui' => $g($r, 'nominal_diakui', 0), 'Sisa' => $g($r, 'sisa', $g($r, 'nominal')),
            ])->all(), 'uang_muka_customer', 'Uang Muka Customer'],
            'uang-muka-operasional' => [collect($this->service->uangMukaOperasional())->map(fn ($r) => [
                'No. Ref' => $g($r, 'nomor_ref'), 'Tanggal' => $tgl($g($r, 'tanggal')), 'Penerima' => $g($r, 'penerima'),
                'Keterangan' => $g($r, 'keterangan'), 'Akun UM' => $g($r, 'nama_coa_uang_muka'),
                'Nominal' => $g($r, 'nominal'), 'Sisa' => $g($r, 'sisa'),
            ])->all(), 'uang_muka_operasional', 'Uang Muka Operasional Outstanding'],
            'accrue-prepaid' => [collect($this->service->accrue())->map(fn ($r) => [
                'No. Ref' => $g($r, 'nomor_referensi'), 'Tanggal' => $tgl($g($r, 'tanggal')), 'Periode' => $g($r, 'periode'),
                'Debet' => $g($r, 'nama_coa_debet'), 'Kredit' => $g($r, 'nama_coa_kredit'), 'Nominal' => $g($r, 'nominal'),
            ])->all(), 'accrue_prepaid', 'Accrue & Prepaid Aktif'],
            'rekap-pembiayaan' => [collect((new \App\Services\Modules\BankLoanService)->rekap())->map(fn ($r) => [
                'Bank' => $g($r, 'nama_bank'), 'Jumlah Pembiayaan' => $g($r, 'jumlah_pinjaman'), 'Pokok Awal' => $g($r, 'pokok_awal'),
                'Terbayar' => $g($r, 'pokok_terbayar'), 'Sisa Pokok' => $g($r, 'sisa_pokok'), 'Margin Dibayar' => $g($r, 'margin_dibayar'),
            ])->all(), 'rekap_pembiayaan_per_bank', 'Rekap Pembiayaan per Bank'],
            default => abort(404),
        };

        return Exporter::download($fmt, $file, $title, $rows);
    }
}
