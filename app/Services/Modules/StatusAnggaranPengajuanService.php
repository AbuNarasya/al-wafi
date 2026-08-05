<?php

namespace App\Services\Modules;

use App\Models\ApprovalFlow;
use App\Models\ApprovalInstance;
use App\Models\OperationalAdvance;
use App\Models\PengajuanPembayaran;
use App\Models\PengajuanPembayaranDetail;
use App\Models\User;
use App\Services\Ledger\AnggaranPeriode;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard tab "Anggaran & Pengajuan" — RINGKASAN, bukan daftar.
 *
 * Angkanya sengaja diambil dari service yang sudah dipakai layar lain
 * (BudgetRealisasiService → BudgetService::realisasi) alih-alih dihitung ulang:
 * kalau dihitung ulang, suatu hari dashboard dan layar Realisasi Anggaran akan
 * berbeda dan tak ada yang tahu mana yang benar.
 *
 * Yang BARU di sini dan tak ada di layar mana pun: KOMITMEN (pengajuan yang
 * sudah diajukan tapi belum berjurnal) ikut dihitung sebagai pemakaian, sama
 * seperti yang dipakai AnggaranPolicy saat memutuskan eskalasi overbudget.
 * Sisa anggaran yang tampak aman bisa sebenarnya sudah habis oleh komitmen —
 * dan itulah yang membuat orang terus mengajukan sampai tembus.
 */
class StatusAnggaranPengajuanService
{
    /** Status yang berarti dokumennya masih hidup (belum selesai, belum batal). */
    public const STATUS_BERJALAN = ['diajukan', 'diverifikasi', 'diposting'];

    public const JENIS = [
        'pembayaran' => 'Pembayaran',
        'uang_muka' => 'Uang Muka',
        'penyelesaian_uang_muka' => 'Penyelesaian UM',
    ];

    public const STATUS_LABEL = [
        'diajukan' => 'Diajukan',
        'diverifikasi' => 'Diverifikasi',
        'diposting' => 'Diposting',
        'lunas' => 'Lunas',
        'ditolak' => 'Ditolak',
        'void' => 'Void',
    ];

    public function __construct(private BudgetRealisasiService $realisasi = new BudgetRealisasiService) {}

    /** Tahun anggaran berjalan (mengikuti bulan awal yang disetel perusahaan). */
    public function tahunBerjalan(): int
    {
        $kini = Carbon::now();

        return AnggaranPeriode::tahunAnggaranDari(
            (int) $kini->format('Y'), (int) $kini->format('n'), AnggaranPeriode::bulanAwalAnggaran(),
        );
    }

    /**
     * Status anggaran: total anggaran vs realisasi vs komitmen, plus akun yang
     * sudah tembus atau belum dianggarkan.
     *
     * Gerbangnya menumpang BudgetRealisasiService::review() — admin & Yayasan
     * melihat semua, Direktorat melihat subtree-nya, Mudir/Staff hanya
     * bagiannya. Melempar AppException 403 bagi yang di luar itu.
     *
     * @return array<string,mixed>
     */
    public function anggaran(int $idPengguna, int $tahun, ?string $kodeBagian = null): array
    {
        $review = $this->realisasi->review($idPengguna, $tahun, $kodeBagian);

        // Lingkup bagian untuk komitmen HARUS sama dengan yang dipakai review,
        // kalau tidak komitmennya bicara tentang bagian lain.
        $lingkup = match (true) {
            $kodeBagian !== null && $kodeBagian !== '' => [$kodeBagian],
            ! empty($review['boleh_semua']) => null,           // semua bagian
            default => array_column($review['bagian_opsi'] ?? [], 'kode_bagian'),
        };

        $komitmen = $this->komitmenPerAkun($tahun, $lingkup);

        $baris = [];
        $totalAnggaran = '0';
        $totalRealisasi = '0';
        $totalKomitmen = '0';
        foreach ($review['rows'] ?? [] as $r) {
            $kom = $komitmen[$r['kode_coa']] ?? '0';
            $terpakai = Money::add($r['total_realisasi'], $kom);
            $anggaran = $r['total_anggaran'];

            $totalAnggaran = Money::add($totalAnggaran, $anggaran);
            $totalRealisasi = Money::add($totalRealisasi, $r['total_realisasi']);
            $totalKomitmen = Money::add($totalKomitmen, $kom);

            $baris[] = [
                'kode_coa' => $r['kode_coa'],
                'nama_coa' => $r['nama_coa'],
                'anggaran' => $anggaran,
                'realisasi' => $r['total_realisasi'],
                'komitmen' => Money::of($kom),
                'terpakai' => $terpakai,
                'sisa' => Money::sub($anggaran, $terpakai),
                'persen' => $this->persen($terpakai, $anggaran),
                // Nol anggaran dengan pemakaian berjalan BUKAN "0% terpakai" —
                // itu belanja yang tak pernah dianggarkan sama sekali.
                'belum_dianggarkan' => Money::isZero($anggaran) && Money::gtZero($terpakai),
                'tembus' => ! Money::isZero($anggaran) && Money::gt($terpakai, $anggaran),
            ];
        }

        // Akun bermasalah didahulukan; sisanya menurut nominal terpakai.
        $perhatian = array_values(array_filter($baris, fn ($b) => $b['tembus'] || $b['belum_dianggarkan']));
        usort($perhatian, fn ($a, $b) => Money::gt($b['terpakai'], $a['terpakai']) ? 1 : -1);

        $terpakaiTotal = Money::add($totalRealisasi, $totalKomitmen);

        return [
            'tahun' => $tahun,
            'label_ta' => $review['label_ta'] ?? (string) $tahun,
            'kode_bagian' => $kodeBagian,
            'bagian_opsi' => $review['bagian_opsi'] ?? [],
            'boleh_semua' => (bool) ($review['boleh_semua'] ?? false),
            'anggaran' => Money::of($totalAnggaran),
            'realisasi' => Money::of($totalRealisasi),
            'komitmen' => Money::of($totalKomitmen),
            'terpakai' => $terpakaiTotal,
            'sisa' => Money::sub($totalAnggaran, $terpakaiTotal),
            'persen' => $this->persen($terpakaiTotal, $totalAnggaran),
            'perhatian' => array_slice($perhatian, 0, 10),
            'jumlah_perhatian' => count($perhatian),
            'ada_anggaran' => ! Money::isZero($totalAnggaran),
        ];
    }

    /**
     * Komitmen per akun sepanjang tahun anggaran: baris pengajuan berstatus
     * "diajukan" yang belum berjurnal. Definisinya SAMA dengan AnggaranPolicy —
     * dua pengajuan @60% tak boleh lolos senyap menjadi 120%.
     *
     * @param  list<string>|null  $lingkupBagian  null = semua bagian
     * @return array<string,string> kode_coa => nominal
     */
    private function komitmenPerAkun(int $tahun, ?array $lingkupBagian): array
    {
        $bulanAwal = AnggaranPeriode::bulanAwalAnggaran();
        $pairs = AnggaranPeriode::bulanTahunAnggaran($tahun, $bulanAwal);
        $awal = AnggaranPeriode::awalTahunAnggaran($tahun, $bulanAwal)->toDateString();
        $akhir = AnggaranPeriode::akhirBulan($pairs[11]['tahun'], $pairs[11]['bulan'])->toDateString();

        if ($lingkupBagian !== null && $lingkupBagian === []) {
            return [];
        }

        return PengajuanPembayaranDetail::query()
            ->whereHas('pengajuan', function ($q) use ($awal, $akhir, $lingkupBagian) {
                $q->where('status', 'diajukan')->whereBetween('tanggal', [$awal, $akhir]);
                if ($lingkupBagian !== null) {
                    $q->whereIn('kode_bagian', $lingkupBagian);
                }
            })
            ->groupBy('kode_coa')
            ->selectRaw('kode_coa, sum(nominal) as total')
            ->pluck('total', 'kode_coa')
            ->map(fn ($v) => Money::of($v))
            ->all();
    }

    /**
     * Status pengajuan per jenis: cacah & nominal tiap status, dokumen yang
     * tertahan paling lama, dan posisi antreannya.
     *
     * @return array<string,mixed>
     */
    public function pengajuan(User $user): array
    {
        $lingkup = fn ($q) => $this->batasiVisibilitas($q, $user);

        $rekap = PengajuanPembayaran::query()
            ->tap($lingkup)
            ->groupBy('jenis', 'status')
            ->get([
                'jenis', 'status',
                DB::raw('count(*) as jumlah'),
                DB::raw('sum(nominal) as total'),
            ]);

        $matriks = [];
        foreach (array_keys(self::JENIS) as $jenis) {
            foreach (array_keys(self::STATUS_LABEL) as $status) {
                $matriks[$jenis][$status] = ['jumlah' => 0, 'total' => '0.00'];
            }
            $matriks[$jenis]['_berjalan'] = ['jumlah' => 0, 'total' => '0.00'];
        }
        foreach ($rekap as $r) {
            if (! isset($matriks[$r->jenis][$r->status])) {
                continue; // jenis/status di luar yang dikenal — jangan diam-diam menambah kolom
            }
            $matriks[$r->jenis][$r->status] = ['jumlah' => (int) $r->jumlah, 'total' => Money::of($r->total)];
            if (in_array($r->status, self::STATUS_BERJALAN, true)) {
                $matriks[$r->jenis]['_berjalan'] = [
                    'jumlah' => $matriks[$r->jenis]['_berjalan']['jumlah'] + (int) $r->jumlah,
                    'total' => Money::add($matriks[$r->jenis]['_berjalan']['total'], $r->total),
                ];
            }
        }

        return [
            'matriks' => $matriks,
            'tertahan' => $this->tertahanTerlama($user),
            'antrean' => $this->antreanTahap($user),
            'uang_muka' => $this->uangMukaOutstanding($user),
        ];
    }

    /**
     * Dokumen yang masih menunggu, diurut dari yang PALING LAMA. Umur inilah
     * yang biasanya jadi masalah nyata — layar daftar mana pun tak pernah
     * menyebutnya, jadi dokumen bisa mengendap berminggu-minggu tanpa terlihat.
     *
     * @return list<array<string,mixed>>
     */
    private function tertahanTerlama(User $user, int $batas = 5): array
    {
        $rows = PengajuanPembayaran::query()
            ->tap(fn ($q) => $this->batasiVisibilitas($q, $user))
            ->whereIn('status', ['diajukan', 'diverifikasi'])
            ->with('bagian')
            ->orderBy('tanggal')->orderBy('id')
            ->limit($batas)
            ->get();

        $hariIni = Carbon::now()->startOfDay();

        return $rows->map(fn ($p) => [
            'id' => $p->id,
            'nomor' => $p->nomor,
            'jenis' => self::JENIS[$p->jenis] ?? $p->jenis,
            'bagian' => $p->bagian?->nama_bagian ?? $p->kode_bagian,
            'nominal' => Money::of($p->nominal),
            'tanggal' => $p->tanggal,
            'umur_hari' => (int) $p->tanggal->copy()->startOfDay()->diffInDays($hariIni),
            'status' => self::STATUS_LABEL[$p->status] ?? $p->status,
        ])->all();
    }

    /**
     * Berapa dokumen menunggu di tiap tahap rantai. Tahap yang sudah tuntas
     * tapi belum diverifikasi keuangan dihitung sebagai antrean tersendiri —
     * di situlah dokumen paling sering mengendap tanpa ada yang merasa ditagih.
     *
     * @return list<array{tahap:string,jumlah:int}>
     */
    private function antreanTahap(User $user): array
    {
        $ids = PengajuanPembayaran::query()
            ->tap(fn ($q) => $this->batasiVisibilitas($q, $user))
            ->pluck('id')->map(fn ($v) => (string) $v)->all();
        if ($ids === []) {
            return [];
        }

        $instances = ApprovalInstance::where('jenis_dokumen', PengajuanPembayaranService::SUMBER)
            ->whereIn('id_dokumen', $ids)
            ->get(['kode_flow', 'tahap_sekarang', 'status', 'posted']);

        $namaTahap = [];
        foreach (ApprovalFlow::with('steps')->whereIn('kode_flow', $instances->pluck('kode_flow')->unique())->get() as $f) {
            foreach ($f->steps as $s) {
                $namaTahap[$f->kode_flow.'|'.$s->urutan] = $s->nama_tahap;
            }
        }

        $hitung = [];
        foreach ($instances as $i) {
            if ($i->status === 'berjalan') {
                $label = $namaTahap[$i->kode_flow.'|'.$i->tahap_sekarang] ?? "Tahap {$i->tahap_sekarang}";
            } elseif ($i->status === 'disetujui' && ! $i->posted) {
                $label = 'Verifikasi Keuangan';
            } else {
                continue;
            }
            $hitung[$label] = ($hitung[$label] ?? 0) + 1;
        }
        arsort($hitung);

        return array_map(fn ($tahap, $jumlah) => ['tahap' => $tahap, 'jumlah' => $jumlah],
            array_keys($hitung), array_values($hitung));
    }

    /**
     * Uang muka yang sudah cair tapi belum diselesaikan — kewajiban yang
     * menggantung di tangan penerimanya, bukan di kas.
     *
     * @return array{jumlah:int,total:string,terlama:array<string,mixed>|null}
     */
    private function uangMukaOutstanding(User $user): array
    {
        $q = OperationalAdvance::query()
            ->where('status', '!=', 'void')
            ->whereColumn('nominal_diselesaikan', '<', 'nominal');
        if (! ($user->is_admin || $user->tim_keuangan)) {
            $q->where('id_pengguna', $user->id_pengguna);
        }

        $rows = $q->orderBy('tanggal')->get();
        $total = '0';
        foreach ($rows as $r) {
            $total = Money::add($total, $r->sisa);
        }
        $terlama = $rows->first();

        return [
            'jumlah' => $rows->count(),
            'total' => Money::of($total),
            'terlama' => $terlama ? [
                'nomor_ref' => $terlama->nomor_ref,
                'penerima' => $terlama->penerima,
                'sisa' => Money::of($terlama->sisa),
                'umur_hari' => (int) Carbon::parse($terlama->tanggal)->startOfDay()->diffInDays(Carbon::now()->startOfDay()),
            ] : null,
        ];
    }

    /**
     * Visibilitas dipinjam UTUH dari daftar Pengajuan Pembayaran, bukan disalin:
     * ringkasan yang memakai aturannya sendiri cepat atau lambat akan menyebut
     * angka yang tak cocok dengan daftar di sebelahnya.
     */
    private function batasiVisibilitas($query, User $user): void
    {
        $query->terlihatOleh($user);
    }

    private function persen(string $terpakai, string $anggaran): ?float
    {
        if (Money::isZero($anggaran)) {
            return null; // tanpa anggaran, persentase tak punya arti
        }

        return round(((float) $terpakai / (float) $anggaran) * 100, 1);
    }
}
