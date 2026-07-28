<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Services\Reports\ReportsService;
use App\Support\Export\DatasetRegistry;
use App\Support\Export\Exporter;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Export Data (port ExportDataPage dev). Tiga export khusus (Jurnal mentah,
 * Buku Besar, Aset per kategori) + browser 22 dataset (Master & Transaksi).
 * Semua ke CSV / Excel (XLSX) / PDF lewat Exporter.
 */
class ExportController extends Controller
{
    public function __construct(private ReportsService $reports = new ReportsService) {}

    public function index(): View
    {
        return view('export.index', [
            'unitOptions' => ['' => 'Semua Unit'] + BusinessUnit::where('status', 'aktif')->orderBy('kode_unit')->pluck('nama_unit', 'kode_unit')->all(),
            'coaOptions' => ['' => '— pilih akun —'] + CoaDetail::where('status', 'aktif')->orderBy('kode_coa')->get()
                ->mapWithKeys(fn ($c) => [$c->kode_coa => "{$c->kode_coa} — {$c->nama_coa}"])->all(),
            'kategoriOptions' => ['' => 'Semua kategori'] + \App\Models\AssetCategory::where('status', 'aktif')->orderBy('nama')->pluck('nama', 'nama')->all(),
            'datasets' => collect(DatasetRegistry::datasets())->groupBy('group'),
            'formats' => Exporter::FORMATS,
        ]);
    }

    /** Data mentah jurnal (per baris). */
    public function jurnalMentah(Request $request)
    {
        $semua = $request->boolean('semua');
        $from = $semua ? null : ($request->query('from') ?: null);
        $to = $semua ? null : ($request->query('to') ?: null);
        $unit = $request->query('kode_unit') ?: null;

        $data = $this->reports->jurnalMentah($from, $to, $unit);
        $rows = array_map(fn ($r) => [
            'Tanggal' => $this->tgl($r['tanggal']),
            'Referensi' => $r['referensi'],
            'COA' => $r['kode_coa'].($r['nama_coa'] ? ' — '.$r['nama_coa'] : ''),
            'Transaksi Kas/Non-Kas' => $r['jenis_transaksi'],
            'Keterangan' => $r['keterangan'],
            'Rekening (Kas/Bank)' => $r['rekening'],
            'Unit Bisnis' => $r['unit_bisnis'],
            'Status' => $r['status'],
            'Debet' => $r['debet'],
            'Kredit' => $r['kredit'],
        ], $data['rows']);

        $file = 'jurnal_entry'.($semua ? '_semua' : '').($unit ? '_'.$unit : '');

        return Exporter::download($this->format($request), $file, 'Data Mentah Jurnal', $rows);
    }

    /** Buku besar satu akun (hutang/piutang/uang muka/akun neraca lain). */
    public function bukuBesar(Request $request)
    {
        $request->validate(['kode_coa' => ['required', 'string', 'exists:coa_detail,kode_coa']]);
        $kode = $request->query('kode_coa');
        $data = $this->reports->bukuBesar($kode, $request->query('from') ?: null, $request->query('to') ?: null);

        $rows = array_map(fn ($r) => [
            'Tanggal' => $this->tgl($r['tanggal'] ?? null),
            'Referensi' => $r['referensi'] ?? '',
            'Keterangan' => $r['keterangan'] ?? '',
            'Unit Bisnis' => $r['unit_bisnis'] ?? '',
            'Debet' => $r['debet'] ?? '0',
            'Kredit' => $r['kredit'] ?? '0',
            'Saldo' => $r['saldo'] ?? '0',
        ], $data['mutasi'] ?? $data['rows'] ?? []);

        return Exporter::download($this->format($request), 'bukubesar_'.$kode, "Buku Besar {$kode}", $rows);
    }

    /** Aset tetap — kolom lengkap + nilai buku, filter kategori. */
    public function aset(Request $request)
    {
        $kategori = $request->query('kategori') ?: null;
        $rows = Asset::orderBy('kode_aset')
            ->when($kategori, fn ($q) => $q->where('kategori_aset', $kategori))
            ->get()->map(fn ($a) => [
                'Kode Aset' => $a->kode_aset,
                'Kategori' => $a->kategori_aset ?? '',
                'Kuantiti' => $a->kuantiti,
                'Harga Perolehan' => $a->harga_perolehan,
                'Tanggal Perolehan' => $this->tgl($a->tanggal_perolehan),
                'Umur Manfaat (bln)' => $a->umur_manfaat,
                'Metode Depresiasi' => $a->metode_depresiasi,
                'Nilai Residu' => $a->nilai_residu,
                'Akumulasi Depresiasi' => $a->akumulasi_depresiasi,
                'Nilai Buku Akhir' => (float) $a->harga_perolehan - (float) $a->akumulasi_depresiasi,
            ])->all();

        return Exporter::download($this->format($request), 'aset'.($kategori ? '_'.$kategori : ''), 'Aset Tetap', $rows);
    }

    /** Satu dataset dari registry (22 Master/Transaksi). */
    public function dataset(Request $request, string $key)
    {
        $meta = DatasetRegistry::meta($key);
        abort_if($meta === null, 404);

        $rows = (new DatasetRegistry)->rows($key);

        return Exporter::download($this->format($request), $meta['file'], $meta['label'], $rows);
    }

    private function format(Request $request): string
    {
        return $request->query('format', 'csv');
    }

    private function tgl($v): string
    {
        if (! $v) {
            return '';
        }

        return $v instanceof \DateTimeInterface ? $v->format('d/m/Y') : \Illuminate\Support\Carbon::parse($v)->format('d/m/Y');
    }
}
