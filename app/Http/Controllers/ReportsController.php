<?php

namespace App\Http\Controllers;

use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Services\Reports\ReportsService;
use App\Support\Export\Exporter;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Laporan keuangan (read-only) — memakai ReportsService yang sudah dikonversi
 * & teruji. Tiap aksi menerima parameter tanggal via query string.
 */
class ReportsController extends Controller
{
    public function __construct(private readonly ReportsService $reports) {}

    private function awalTahun(): string
    {
        return now()->startOfYear()->toDateString();
    }

    public function index(): View
    {
        return view('reports.index');
    }

    public function neraca(Request $request): View
    {
        $asOf = $request->query('as_of', now()->toDateString());

        return view('reports.neraca', ['data' => $this->reports->neraca($asOf), 'asOf' => $asOf]);
    }

    public function labaRugi(Request $request): View
    {
        [$from, $to] = $this->rentang($request);
        $unit = $this->unitDipilih($request);

        return view('reports.laba-rugi', [
            'data' => $this->reports->labaRugi($from, $to, $unit),
            'from' => $from, 'to' => $to, 'unit' => $unit,
            'unitOptions' => BusinessUnit::where('status', 'aktif')
                ->orderBy('kode_unit')->pluck('nama_unit', 'kode_unit')->all(),
        ]);
    }

    public function neracaSaldo(Request $request): View
    {
        [$from, $to] = $this->rentang($request);
        $unit = $this->unitDipilih($request);

        return view('reports.neraca-saldo', [
            'data' => $this->reports->neracaSaldo($from, $to, $unit),
            'from' => $from, 'to' => $to, 'unit' => $unit,
            'unitOptions' => BusinessUnit::where('status', 'aktif')
                ->orderBy('kode_unit')->pluck('nama_unit', 'kode_unit')->all(),
        ]);
    }

    /** Unit bisnis terpilih; unit yang tak dikenal diabaikan (dianggap semua unit). */
    private function unitDipilih(Request $request): ?string
    {
        $unit = trim((string) $request->query('kode_unit', ''));

        return $unit !== '' && BusinessUnit::whereKey($unit)->exists() ? $unit : null;
    }

    public function perubahanModal(Request $request): View
    {
        [$from, $to] = $this->rentang($request);

        return view('reports.perubahan-modal', ['data' => $this->reports->perubahanModal($from, $to), 'from' => $from, 'to' => $to]);
    }

    public function arusKas(Request $request): View
    {
        [$from, $to] = $this->rentang($request);
        $unit = $this->unitDipilih($request);

        return view('reports.arus-kas', [
            'data' => $this->reports->arusKas($from, $to, $unit),
            'from' => $from, 'to' => $to, 'unit' => $unit,
            'unitOptions' => BusinessUnit::where('status', 'aktif')
                ->orderBy('kode_unit')->pluck('nama_unit', 'kode_unit')->all(),
        ]);
    }

    public function bukuBesar(Request $request): View
    {
        [$from, $to] = $this->rentang($request);
        $kodeCoa = $request->query('kode_coa');
        $unit = $this->unitDipilih($request);
        $data = $kodeCoa ? $this->reports->bukuBesar($kodeCoa, $from, $to, $unit) : null;

        $akunList = CoaDetail::orderBy('kode_coa')->get()
            ->mapWithKeys(fn ($a) => [$a->kode_coa => "{$a->kode_coa} — {$a->nama_coa}"])->all();
        $unitOptions = BusinessUnit::where('status', 'aktif')
            ->orderBy('kode_unit')->pluck('nama_unit', 'kode_unit')->all();

        return view('reports.buku-besar', compact('data', 'akunList', 'kodeCoa', 'from', 'to', 'unit', 'unitOptions'));
    }

    public function aset(): View
    {
        return view('reports.aset', ['data' => $this->reports->laporanAset()]);
    }

    public function persediaan(): View
    {
        return view('reports.persediaan', ['data' => $this->reports->laporanPersediaan()]);
    }

    public function jurnalMentah(Request $request): View
    {
        [$from, $to] = $this->rentang($request);

        // `jurnalMentah()` mengembalikan ['from','to','rows'] — yang dioper ke
        // layar HANYA barisnya. Sebelum ini seluruh bungkusnya yang dioper,
        // sehingga baris pertama yang ditemui view adalah string tanggal dan
        // halamannya SELALU gagal 500, di ponsel maupun di desktop. Jalur
        // unduhannya memakai ['rows'] dengan benar, jadi kesalahannya hanya di
        // sini — dan tak ada test yang pernah membuka halaman ini.
        return view('reports.jurnal', [
            'rows' => $this->reports->jurnalMentah($from, $to)['rows'],
            'from' => $from,
            'to' => $to,
        ]);
    }

    /** @return array{0:string,1:string} */
    private function rentang(Request $request): array
    {
        return [
            $request->query('from', $this->awalTahun()),
            $request->query('to', now()->toDateString()),
        ];
    }

    /** Unduh laporan (CSV/XLSX/PDF) — memakai data yang sama dgn tampilan. */
    public function download(Request $request, string $type)
    {
        $fmt = $request->query('format', 'csv');
        [$from, $to] = $this->rentang($request);
        $tgl = fn ($v) => $v ? Carbon::parse($v)->format('d/m/Y') : '';

        [$rows, $file, $title] = match ($type) {
            'neraca' => (function () use ($request) {
                $asOf = $request->query('as_of', now()->toDateString());
                $d = $this->reports->neraca($asOf);
                $rows = [
                    ...$this->flattenSection('ASET', $d['aset']),
                    ...$this->flattenSection('LIABILITAS', $d['liabilitas']),
                    ...$this->flattenSection('EKUITAS', $d['ekuitas']),
                    ['Bagian' => 'EKUITAS', 'Grup' => 'Laba/Rugi Tahun Berjalan', 'Kode COA' => '', 'Akun' => 'Laba/Rugi Tahun Berjalan', 'Nilai' => $d['ekuitas']['laba_berjalan']],
                    ['Bagian' => 'TOTAL', 'Grup' => '', 'Kode COA' => '', 'Akun' => 'Total Aset', 'Nilai' => $d['total_aset'] ?? $d['aset']['total']],
                    ['Bagian' => 'TOTAL', 'Grup' => '', 'Kode COA' => '', 'Akun' => 'Total Liabilitas + Ekuitas', 'Nilai' => (string) ((float) $d['total_liabilitas'] + (float) $d['total_ekuitas'])],
                ];

                return [$rows, "neraca_{$asOf}", "Neraca per {$asOf}"];
            })(),
            'laba-rugi' => (function () use ($request, $from, $to) {
                $unit = $this->unitDipilih($request);
                $d = $this->reports->labaRugi($from, $to, $unit);
                $rows = [
                    ...$this->flattenSection('PENDAPATAN', $d['pendapatan']),
                    ...$this->flattenSection('BEBAN', $d['beban']),
                    ['Bagian' => 'RINGKASAN', 'Grup' => '', 'Kode COA' => '', 'Akun' => 'Total Pendapatan', 'Nilai' => $d['pendapatan']['total']],
                    ['Bagian' => 'RINGKASAN', 'Grup' => '', 'Kode COA' => '', 'Akun' => 'Total Beban', 'Nilai' => $d['beban']['total']],
                    ['Bagian' => 'RINGKASAN', 'Grup' => '', 'Kode COA' => '', 'Akun' => 'Laba/Rugi Bersih', 'Nilai' => $d['laba_rugi_bersih']],
                ];

                // Unit ikut ke nama berkas & judul: unduhan per unit yang tak
                // bertanda mudah tertukar dengan laporan seluruh unit.
                $namaUnit = $unit ? (BusinessUnit::find($unit)?->nama_unit ?? $unit) : null;
                $berkas = 'laba_rugi_'.($unit ? Str::slug($unit).'_' : '')."{$from}_{$to}";
                $judul = "Laba Rugi {$from} s.d. {$to}".($namaUnit ? " — Unit {$namaUnit}" : '');

                return [$rows, $berkas, $judul];
            })(),
            'perubahan-modal' => (function () use ($from, $to) {
                $d = $this->reports->perubahanModal($from, $to);
                $rows = array_map(fn ($r) => [
                    'Kode COA' => $r['kode_coa'], 'Akun' => $r['nama_coa'], 'Saldo Awal' => $r['saldo_awal'],
                    'Setoran/Penarikan' => $r['mutasi'], 'Saldo Sebelum Laba' => $r['saldo_akhir'],
                ], $d['rows']);
                $rows[] = ['Kode COA' => '', 'Akun' => 'Subtotal Modal', 'Saldo Awal' => $d['total_awal'], 'Setoran/Penarikan' => $d['total_mutasi'], 'Saldo Sebelum Laba' => $d['total_sebelum_laba']];
                $rows[] = ['Kode COA' => '', 'Akun' => 'Laba/Rugi Tahun Berjalan', 'Saldo Awal' => '', 'Setoran/Penarikan' => '', 'Saldo Sebelum Laba' => $d['laba_berjalan']];
                $rows[] = ['Kode COA' => '', 'Akun' => 'Total Ekuitas Akhir', 'Saldo Awal' => '', 'Setoran/Penarikan' => '', 'Saldo Sebelum Laba' => $d['total_ekuitas_akhir']];

                return [$rows, "perubahan_modal_{$from}_{$to}", "Perubahan Modal {$from} s.d. {$to}"];
            })(),
            'arus-kas' => (function () use ($request, $from, $to) {
                $unit = $this->unitDipilih($request);
                $d = $this->reports->arusKas($from, $to, $unit);

                $rows = [];
                foreach ($d['kelompok'] as $k) {
                    foreach ($k['baris'] as $b) {
                        $rows[] = ['Kelompok' => $k['label'], 'Kode COA' => $b['kode_coa'], 'Akun' => $b['nama_coa'], 'Arus Kas' => $b['arus']];
                    }
                    if ($k['baris'] !== []) {
                        $rows[] = ['Kelompok' => $k['label'], 'Kode COA' => '', 'Akun' => 'Subtotal '.$k['label'], 'Arus Kas' => $k['total']];
                    }
                }
                $rows[] = ['Kelompok' => 'RINGKASAN', 'Kode COA' => '', 'Akun' => 'Saldo Kas Awal', 'Arus Kas' => $d['saldo_kas_awal']];
                $rows[] = ['Kelompok' => 'RINGKASAN', 'Kode COA' => '', 'Akun' => 'Arus Kas Bersih', 'Arus Kas' => $d['arus_bersih']];
                $rows[] = ['Kelompok' => 'RINGKASAN', 'Kode COA' => '', 'Akun' => 'Saldo Kas Akhir', 'Arus Kas' => $d['saldo_kas_akhir']];

                // Jembatan laba → kas ikut terbawa: tanpa itu, unduhan tak bisa
                // menjawab pertanyaan yang justru paling sering diajukan atasnya.
                foreach ($d['jembatan']['baris'] ?? [] as $b) {
                    $rows[] = ['Kelompok' => 'JEMBATAN LABA → KAS', 'Kode COA' => '', 'Akun' => $b['label'], 'Arus Kas' => $b['nilai']];
                }

                $namaUnit = $unit ? (BusinessUnit::find($unit)?->nama_unit ?? $unit) : null;
                $berkas = 'arus_kas_'.($unit ? Str::slug($unit).'_' : '')."{$from}_{$to}";
                $judul = "Arus Kas {$from} s.d. {$to}".($namaUnit ? " — Unit {$namaUnit}" : '');

                return [$rows, $berkas, $judul];
            })(),
            'neraca-saldo' => (function () use ($request, $from, $to) {
                $unit = $this->unitDipilih($request);
                $d = $this->reports->neracaSaldo($from, $to, $unit);
                $rows = array_map(fn ($r) => [
                    'Kode COA' => $r['kode_coa'], 'Akun' => $r['nama_coa'], 'Kelompok' => $r['kelompok'],
                    'Saldo Awal (D)' => $r['awal_debet'], 'Saldo Awal (K)' => $r['awal_kredit'],
                    'Mutasi (D)' => $r['mutasi_debet'], 'Mutasi (K)' => $r['mutasi_kredit'],
                    'Saldo Akhir (D)' => $r['akhir_debet'], 'Saldo Akhir (K)' => $r['akhir_kredit'],
                ], $d['rows']);
                $t = $d['total'];
                $rows[] = [
                    'Kode COA' => '', 'Akun' => 'TOTAL', 'Kelompok' => '',
                    'Saldo Awal (D)' => $t['awal_debet'], 'Saldo Awal (K)' => $t['awal_kredit'],
                    'Mutasi (D)' => $t['mutasi_debet'], 'Mutasi (K)' => $t['mutasi_kredit'],
                    'Saldo Akhir (D)' => $t['akhir_debet'], 'Saldo Akhir (K)' => $t['akhir_kredit'],
                ];

                $namaUnit = $unit ? (BusinessUnit::find($unit)?->nama_unit ?? $unit) : null;
                $berkas = 'neraca_saldo_'.($unit ? Str::slug($unit).'_' : '')."{$from}_{$to}";
                $judul = "Neraca Saldo {$from} s.d. {$to}".($namaUnit ? " — Unit {$namaUnit}" : '');

                return [$rows, $berkas, $judul];
            })(),
            'buku-besar' => (function () use ($request, $from, $to, $tgl) {
                $kode = $request->query('kode_coa');
                abort_if(! $kode, 400, 'Pilih akun dulu.');
                $d = $this->reports->bukuBesar($kode, $from, $to, $this->unitDipilih($request));
                $rows = [['Tanggal' => '', 'Referensi' => '', 'Keterangan' => 'Saldo Awal Periode', 'Debet' => '', 'Kredit' => '', 'Saldo' => $d['saldo_awal']]];
                foreach ($d['mutasi'] as $m) {
                    $rows[] = ['Tanggal' => $tgl($m['tanggal']), 'Referensi' => $m['referensi'], 'Keterangan' => $m['keterangan'], 'Debet' => $m['debet'], 'Kredit' => $m['kredit'], 'Saldo' => $m['saldo']];
                }
                $rows[] = ['Tanggal' => '', 'Referensi' => '', 'Keterangan' => 'Saldo Akhir', 'Debet' => '', 'Kredit' => '', 'Saldo' => $d['saldo_akhir']];

                return [$rows, "buku_besar_{$kode}", "Buku Besar {$kode}"];
            })(),
            'aset' => (function () {
                $d = $this->reports->laporanAset();
                $rows = array_map(fn ($r) => [
                    'Kode Aset' => $r['kode_aset'], 'Nama Aset' => $r['nama_aset'], 'Kategori' => $r['kategori_aset'],
                    'Harga Perolehan' => $r['harga_perolehan'], 'Akumulasi Depresiasi' => $r['akumulasi_depresiasi'],
                    'Nilai Buku' => $r['nilai_buku'], 'Depresiasi/Bln' => $r['depresiasi_bulanan'], 'Status' => $r['status'],
                ], $d['rows']);

                return [$rows, 'laporan_aset', 'Laporan Aset & Depresiasi'];
            })(),
            'persediaan' => (function () {
                $d = $this->reports->laporanPersediaan();
                $rows = array_map(fn ($r) => [
                    'Kode' => $r['kode_persediaan'], 'Nama Item' => $r['nama_persediaan'], 'Satuan' => $r['satuan'],
                    'Stok Masuk' => $r['stok_masuk'], 'Stok Keluar' => $r['stok_keluar'], 'Stok Saat Ini' => $r['stok'],
                    'Harga Perolehan' => $r['harga_perolehan'], 'Nilai Total' => $r['nilai_total'],
                ], $d['rows']);

                return [$rows, 'laporan_persediaan', 'Laporan Persediaan'];
            })(),
            'jurnal' => (function () use ($from, $to, $tgl) {
                $d = $this->reports->jurnalMentah($from, $to);
                $rows = array_map(fn ($r) => [
                    'Tanggal' => $tgl($r['tanggal']), 'Referensi' => $r['referensi'], 'Akun' => $r['kode_coa'].' — '.$r['nama_coa'],
                    'Jenis' => $r['jenis_transaksi'], 'Keterangan' => $r['keterangan'], 'Unit Bisnis' => $r['unit_bisnis'],
                    'Debet' => $r['debet'], 'Kredit' => $r['kredit'], 'Status' => $r['status'],
                ], $d['rows']);

                return [$rows, "jurnal_{$from}_{$to}", 'Data Mentah Jurnal'];
            })(),
            default => abort(404),
        };

        return Exporter::download($fmt, $file, $title, $rows);
    }

    /**
     * Ratakan bagian bergaya Neraca/Laba Rugi (grup → akun) ke baris export.
     *
     * @return array<int,array<string,scalar|null>>
     */
    private function flattenSection(string $bagian, array $section): array
    {
        $out = [];
        foreach ($section['groups'] as $g) {
            foreach ($g['accounts'] as $a) {
                $out[] = ['Bagian' => $bagian, 'Grup' => $g['nama_grup'], 'Kode COA' => $a['kode_coa'], 'Akun' => $a['nama_coa'], 'Nilai' => $a['nilai']];
            }
        }

        return $out;
    }
}
