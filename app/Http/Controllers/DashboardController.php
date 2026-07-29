<?php

namespace App\Http\Controllers;

use App\Models\CompanySettings;
use App\Services\Modules\DashboardService;
use App\Services\Modules\PpsbDashboardService;
use App\Support\Akses;
use App\Support\Export\Exporter;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Dashboard — SATU halaman, isinya dipisah per TAB dengan hak akses sendiri:
 *   • keuangan (modul `dashboard`)  — port DashboardPage.tsx: kartu headline,
 *     drill-down, cash flow, laba rugi per unit, pencapaian, outstanding approval.
 *   • ppsb (modul `dashboard-ppsb`) — pendaftar & closing bulanan, outstanding
 *     closing, penerimaan, plan vs aktual, ranking sumber informasi.
 *
 * Gerbang aksesnya di SINI, bukan di middleware rute: satu rute melayani dua tab
 * dengan hak berbeda, jadi middleware `hakakses:dashboard` akan salah menolak
 * panitia PPSB yang hanya berhak atas tabnya sendiri.
 */
class DashboardController extends Controller
{
    /** Kartu PPSB yang punya rincian & unduhan. */
    private const JENIS_RINCIAN = ['registrasi', 'uang_pangkal', 'perlengkapan', 'total', 'outstanding'];

    private const JUDUL_RINCIAN = [
        'registrasi' => 'Rincian Penerimaan Registrasi',
        'uang_pangkal' => 'Rincian Penerimaan Uang Pangkal',
        'perlengkapan' => 'Rincian Penerimaan Biaya Perlengkapan',
        'total' => 'Rincian Total Penerimaan',
        'outstanding' => 'Rincian Outstanding Closing',
    ];

    public function __construct(private DashboardService $svc = new DashboardService) {}

    public function index(Request $request): View
    {
        $tabs = array_filter([
            'keuangan' => Akses::boleh('dashboard', 'lihat') ? 'Keuangan' : null,
            'ppsb' => Akses::boleh('dashboard-ppsb', 'lihat') ? 'PPSB' : null,
        ]);
        abort_if($tabs === [], 403, 'Anda belum diberi akses ke dashboard mana pun.');

        // Tab yang diminta tapi tak berhak → jatuh ke tab pertama yang boleh,
        // bukan 403: pengguna hanya salah menekan tautan, bukan menyusup.
        $tab = (string) $request->query('tab');
        if (! isset($tabs[$tab])) {
            $tab = array_key_first($tabs);
        }

        return view('dashboard.index', [
            'tab' => $tab,
            'tabs' => $tabs,
            ...($tab === 'ppsb' ? $this->dataPpsb($request) : $this->dataKeuangan()),
        ]);
    }

    /** @return array<string,mixed> */
    private function dataKeuangan(): array
    {
        $lines = $this->svc->lines();
        $bankSet = $this->svc->bankSet();
        $unitName = $this->svc->unitName();

        return [
            'perusahaan' => CompanySettings::find(1),
            'summary' => $this->svc->summary(),
            'kas' => $this->svc->kasRekening(),
            'hutang' => [
                'pendek' => $this->svc->hutang('pendek'),
                'panjang' => $this->svc->hutang('panjang'),
                'pajak' => $this->svc->hutang('pajak'),
            ],
            'cashFlow' => $this->svc->cashFlow($lines, $bankSet),
            'cashFlowUnit' => $this->svc->cashFlowUnit($lines, $bankSet, $unitName),
            'labaRugiUnit' => $this->svc->labaRugiUnit($lines, $unitName),
            'pencapaian' => $this->svc->pencapaian($lines),
            'approvals' => $this->svc->approvals(),
        ];
    }

    /** @return array<string,mixed> */
    private function dataPpsb(Request $request): array
    {
        $ppsb = new PpsbDashboardService;
        $opsiTa = $ppsb->opsiTa();
        $ta = (string) $request->query('ta');
        if (! isset($opsiTa[$ta])) {
            $ta = (string) $ppsb->taDefault();
        }

        // Sebaran jalur bisa dibaca dari dua sisi; default closing agar selaras
        // dengan tabel plan vs aktual tepat di atasnya.
        $basisJalur = $request->query('jalur') === 'pendaftar' ? 'pendaftar' : 'closing';

        // Rincian kartu hanya dihitung saat diminta — daftarnya bisa panjang dan
        // tak ada gunanya dimuat pada setiap kunjungan dashboard. Terpaginasi
        // supaya ratusan santri tak dirender sekaligus.
        $detail = (string) $request->query('detail', '');
        $detailValid = in_array($detail, self::JENIS_RINCIAN, true);
        $cari = trim((string) $request->query('cari', ''));
        $rincian = $detailValid ? $ppsb->rincian($ta, $detail, $cari) : null;

        return [
            'ta' => $ta,
            'opsiTa' => $opsiTa,
            'basisJalur' => $basisJalur,
            'jalur' => $ppsb->sebaranJalur($ta, $basisJalur),
            'detail' => $detailValid ? $detail : null,
            'rincian' => $rincian,
            'cari' => $cari,
            'masterSiap' => $ta !== '' && $ppsb->masterSiap($ta),
            'pendaftar' => $ppsb->tabelBulanan($ta, 'pendaftar'),
            'closing' => $ppsb->tabelBulanan($ta, 'closing'),
            'trenPendaftar' => $ppsb->trenBulanan('pendaftar', $ta),
            'trenClosing' => $ppsb->trenBulanan('closing', $ta),
            'outstanding' => $ppsb->outstandingClosing($ta),
            'penerimaan' => $ppsb->penerimaan($ta),
            'plan' => $ppsb->planVsAktual($ta),
            'sumber' => $ppsb->sumberInformasi($ta),
        ];
    }

    /**
     * Unduh rincian kartu PPSB (CSV/Excel/PDF) — memakai penyaring & pencarian
     * yang sama dengan yang sedang dilihat, tetapi TANPA paginasi: berkas unduhan
     * harus utuh, bukan sekadar halaman yang kebetulan terbuka.
     */
    public function exportPpsb(Request $request, string $jenis)
    {
        abort_unless(Akses::boleh('dashboard-ppsb', 'lihat'), 403);
        abort_unless(in_array($jenis, self::JENIS_RINCIAN, true), 404);

        $ppsb = new PpsbDashboardService;
        $ta = (string) $request->query('ta', '');
        if (! isset($ppsb->opsiTa()[$ta])) {
            $ta = (string) $ppsb->taDefault();
        }
        $cari = trim((string) $request->query('cari', ''));

        $rows = collect($ppsb->rincian($ta, $jenis, $cari, semua: true))->map(fn ($r) => $jenis === 'outstanding' ? [
            'No. Pendaftaran' => $r->no_pendaftaran,
            'NIS' => $r->nis,
            'Nama' => $r->nama,
            'Jenjang' => $r->jenjang,
            'Jalur' => $r->jalur,
            'Tagihan' => $r->nominal,
            'Terbayar' => $r->terbayar,
            'Sisa' => $r->sisa,
            'Jatuh Tempo' => $r->jatuh_tempo,
        ] : [
            'No. Pendaftaran' => $r->no_pendaftaran,
            'NIS' => $r->nis,
            'Nama' => $r->nama,
            'Jenjang' => $r->jenjang,
            'Jalur' => $r->jalur,
            'Registrasi' => $r->registrasi,
            'Uang Pangkal' => $r->uang_pangkal,
            'Perlengkapan' => $r->perlengkapan,
            'Total' => $r->total,
            'Jumlah Pembayaran' => $r->jumlah_bayar,
            'Pembayaran Terakhir' => $r->terakhir,
        ])->all();

        return Exporter::download(
            (string) $request->query('format', 'csv'),
            'ppsb_'.$jenis.'_'.str_replace('/', '-', $ta),
            self::JUDUL_RINCIAN[$jenis].' — T.A '.$ta,
            $rows,
        );
    }

    /** Unduh panel dashboard (CSV/Excel/PDF). */
    public function download(Request $request, string $type)
    {
        $fmt = $request->query('format', 'csv');
        $mode = $request->query('mode', 'total') === 'bulanan' ? 'bulanan' : 'total';
        $lines = $this->svc->lines();

        [$rows, $file, $title] = match ($type) {
            'kas-rekening' => [collect($this->svc->kasRekening()['rincian'])->map(fn ($r) => [
                'Rekening' => $r['nama'], 'Jenis' => $r['jenis'], 'Bank' => $r['nama_bank'], 'No. Rekening' => $r['no_rekening'], 'Saldo' => $r['saldo'],
            ])->all(), 'saldo_kas_rekening', 'Saldo Kas & Rekening'],
            'hutang' => (function () use ($request) {
                $jenis = in_array($request->query('jenis'), ['pendek', 'panjang', 'pajak'], true) ? $request->query('jenis') : 'pendek';
                $label = ['pendek' => 'Hutang Jangka Pendek', 'panjang' => 'Hutang Jangka Panjang', 'pajak' => 'Hutang Pajak'][$jenis];
                $rows = collect($this->svc->hutang($jenis)['rincian'])->map(fn ($r) => [
                    'Akun' => $r['kode_coa'].' — '.$r['nama_coa'], 'Saldo Awal' => $r['saldo_awal'], 'Penambahan' => $r['penambahan'], 'Pengurangan' => $r['pengurangan'], 'Saldo Akhir' => $r['saldo'],
                ])->all();

                return [$rows, 'hutang_'.$jenis, $label];
            })(),
            'cash-flow' => [collect($this->svc->cashFlow($lines, $this->svc->bankSet())[$mode])->map(fn ($r) => [
                'Periode' => $r['periode'], 'Kas Masuk' => $r['masuk'], 'Kas Keluar' => $r['keluar'], 'Arus Kas Bersih' => $r['net'],
            ])->all(), 'resume_cashflow', 'Resume Cash Flow'],
            'cash-flow-unit' => [collect($this->svc->cashFlowUnit($lines, $this->svc->bankSet(), $this->svc->unitName())[$mode])->map(fn ($r) => [
                'Unit Bisnis' => $r['nama_unit'], 'Periode' => $r['periode'], 'Masuk' => $r['masuk'], 'Keluar' => $r['keluar'], 'Bersih' => $r['net'],
            ])->all(), 'resume_cashflow_unit', 'Resume Cash Flow per Unit'],
            'laba-rugi-unit' => [collect($this->svc->labaRugiUnit($lines, $this->svc->unitName())[$mode])->map(fn ($r) => [
                'Unit Bisnis' => $r['nama_unit'], 'Periode' => $r['periode'], 'Pendapatan' => $r['pendapatan'], 'Beban' => $r['beban'], 'Laba/Rugi' => $r['laba'],
            ])->all(), 'resume_labarugi_unit', 'Resume Laba Rugi per Unit'],
            'pencapaian' => [collect($this->svc->pencapaian($lines)[$mode])->map(fn ($r) => [
                'Periode' => $r['periode'], 'Pengakuan Piutang' => $r['pengakuan'], 'Realisasi Pembayaran' => $r['realisasi'], 'Outstanding' => $r['outstanding'], '% Realisasi' => $r['persen_realisasi'],
            ])->all(), 'pencapaian_pendapatan', 'Pencapaian Pendapatan'],
            default => abort(404),
        };

        return Exporter::download($fmt, $file, $title, $rows);
    }
}
