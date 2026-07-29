<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\Bagian;
use App\Models\Budget;
use App\Models\BudgetPengajuan;
use App\Models\CoaDetail;
use App\Models\User;
use App\Services\Ledger\AnggaranPeriode;
use App\Services\Ledger\DocNumber;
use App\Services\Ledger\PeringkatPengajuan;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * PENGAJUAN ANGGARAN (§3.c) — Budget lewat mesin approval.
 *
 * Berbeda dari `BudgetService::save` (jalur admin, tulis langsung), penyusun
 * mengajukan satu SCOPE (Tahun Anggaran + bagian + unit) yang melewati rantai
 * BUDGET-STD (Mudir Bagian → Mudir Umum → Ketua Yayasan). Saat rantai SELESAI,
 * applyApproved() menuliskannya ke tabel `budgets` live — MENGGANTI scope itu
 * (akun yang tak disebut = dihapus). Usulan yang menggantung tidak memengaruhi
 * realisasi maupun cek overbudget: anggaran yang belum disetujui bukan anggaran.
 *
 * `jenis_dokumen = "Budget"` menyesuaikan flow BUDGET-STD yang ter-seed.
 *
 * ⚠️ Sengaja TANPA evaluasi overbudget saat submit: anggaran ADALAH tolok ukur,
 * tak diukur terhadap dirinya sendiri. Rantainya = 3 tahap tetap (tanpa eskalasi).
 */
class BudgetPengajuanService
{
    public const SUMBER = 'Budget';

    public function __construct(
        private BudgetService $budget = new BudgetService,
        private BudgetLockService $lock = new BudgetLockService,
    ) {}

    private function approval(): ApprovalService
    {
        return new ApprovalService;
    }

    private function notif(): NotificationService
    {
        return new NotificationService;
    }

    /**
     * Ajukan anggaran satu scope ke BUDGET-STD. Pengaju: Staff (4) atau Mudir
     * Bagian (3). Bila Mudir Bagian yang mengajukan, tahap 1 (Mudir Bagian)
     * disetujui otomatis agar ia tidak menyetujui dirinya sendiri.
     *
     * @param  array{tahun:int,kode_unit?:?string,keterangan?:?string,items:array<int,array{kode_coa:string,bulan:int,nominal:string}>}  $input
     */
    public function create(array $input, int $idPengguna): BudgetPengajuan
    {
        $pemohon = User::find($idPengguna);
        if (! $pemohon || $pemohon->status !== 'aktif') {
            throw new AppException(401, 'Pengguna tidak ditemukan.');
        }
        $peringkat = $pemohon->peringkat_pengajuan;
        if ($peringkat !== PeringkatPengajuan::STAFF && $peringkat !== PeringkatPengajuan::MUDIR_BAGIAN) {
            throw new AppException(403, 'Hanya Staff atau Mudir Bagian yang boleh mengajukan anggaran.');
        }
        // Bagian dari PROFIL, bukan pilihan — orang mengajukan atas nama bagiannya.
        if (! $pemohon->kode_bagian) {
            throw new AppException(422, 'Profil Anda belum ditempatkan di bagian mana pun. Minta administrator mengisi Bagian pada akun Anda.');
        }
        $kodeBagian = $pemohon->kode_bagian;
        $bagian = Bagian::find($kodeBagian);
        if (! $bagian) {
            throw new AppException(400, 'Bagian pada profil Anda tidak ditemukan.');
        }
        if ($bagian->status !== 'aktif') {
            throw new AppException(422, "Bagian \"{$bagian->nama_bagian}\" berstatus nonaktif.");
        }

        // TA terkunci → tak boleh ada pengajuan baru untuknya.
        $this->lock->assertTidakTerkunci($input['tahun']);

        // Aturan akun sama persis dgn jalur admin (satu sumber, tak menyimpang).
        $this->budget->assertItemsBeban($input['items']);

        $items = array_values(array_filter($input['items'], fn ($it) => Money::gt($it['nominal'], 0)));
        if ($items === []) {
            throw new AppException(422, 'Semua baris bernilai 0 — tidak ada yang diajukan.');
        }

        $total = '0';
        foreach ($items as $it) {
            $total = Money::add($total, $it['nominal']);
        }

        $bulanAwal = AnggaranPeriode::bulanAwalAnggaran();
        $scope = ! empty($input['kode_unit']) ? $input['kode_unit'] : null;
        $names = CoaDetail::whereIn('kode_coa', array_column($items, 'kode_coa'))->pluck('nama_coa', 'kode_coa');

        $rec = DB::transaction(function () use ($input, $items, $total, $bulanAwal, $scope, $kodeBagian, $idPengguna, $names) {
            $base = DocNumber::docBase('PA', now());
            $last = BudgetPengajuan::where('nomor', 'like', $base.'%')->orderByDesc('nomor')->value('nomor');

            $rec = BudgetPengajuan::create([
                'nomor' => DocNumber::nextDocNumber($base, $last),
                'tahun' => $input['tahun'],
                'bulan_awal' => $bulanAwal,
                'kode_bagian' => $kodeBagian,
                'kode_unit' => $scope,
                'status' => 'diajukan',
                'nominal' => $total,
                'keterangan' => $input['keterangan'] ?? null,
                'id_pengguna' => $idPengguna,
            ]);

            foreach ($items as $it) {
                $rec->details()->create([
                    'kode_coa' => $it['kode_coa'],
                    'nama_coa' => $names[$it['kode_coa']] ?? $it['kode_coa'],
                    'bulan' => $it['bulan'], // SLOT 1..12 di TA
                    'nominal' => $it['nominal'],
                ]);
            }

            return $rec;
        });

        $inst = $this->approval()->submit([
            'jenis_dokumen' => self::SUMBER,
            'id_dokumen' => (string) $rec->id,
            'kode_bagian' => $kodeBagian,
            'nominal' => $total,
            'id_pemohon' => $idPengguna,
        ]);

        if ($peringkat === PeringkatPengajuan::MUDIR_BAGIAN) {
            $this->approval()->approve(
                $inst->id,
                $idPengguna,
                'Disetujui otomatis — pengaju adalah Mudir Bagian; tahap bagian dilewati.',
            );
        }

        return $rec->refresh();
    }

    /**
     * Dipanggil mesin saat rantai SELESAI (daftarHandler, di LUAR transaksi
     * approval). Menuliskan snapshot ke `budgets` live — MENGGANTI scope penuh.
     */
    public function applyApproved(string $idDokumen): void
    {
        $id = (int) $idDokumen;

        DB::transaction(function () use ($id) {
            $rec = BudgetPengajuan::with('details')->find($id);
            if (! $rec || $rec->status !== 'diajukan') {
                return; // sudah diterapkan / final — jangan dobel
            }

            // TA terkunci di tengah rantai (admin mengunci setelah pengajuan
            // diajukan): JANGAN timpa anggaran yang beku. Tandai ditolak + beri
            // tahu pemohon untuk mengajukan ulang setelah dibuka.
            if ($this->lock->isTerkunci($rec->tahun)) {
                $rec->update(['status' => 'ditolak']);
                $this->notif()->kirim([[
                    'id_pengguna' => $rec->id_pengguna,
                    'judul' => 'Pengajuan anggaran tidak diterapkan — TA terkunci',
                    'pesan' => "{$rec->nomor} disetujui, tetapi Tahun Anggaran {$rec->tahun} sudah dikunci admin sehingga anggaran tidak diperbarui. Ajukan ulang setelah kunci dibuka.",
                    'jenis' => 'budget_terkunci',
                    'ref_jenis' => self::SUMBER,
                    'ref_id' => (string) $rec->id,
                ]]);

                return;
            }

            $pairs = AnggaranPeriode::bulanTahunAnggaran($rec->tahun, $rec->bulan_awal);
            $scope = $rec->kode_unit;

            // Ganti SCOPE penuh: hapus seluruh anggaran (semua akun) di 12 bulan
            // TA ini untuk (bagian, unit), lalu tulis snapshot. Akun yang tidak
            // disebut usulan = terhapus.
            $q = Budget::where('kode_bagian', $rec->kode_bagian)
                ->where(function ($w) use ($pairs) {
                    foreach ($pairs as $p) {
                        $w->orWhere(fn ($qq) => $qq->where('tahun', $p['tahun'])->where('bulan', $p['bulan']));
                    }
                });
            $scope === null ? $q->whereNull('kode_unit') : $q->where('kode_unit', $scope);
            $q->delete();

            foreach ($rec->details as $d) {
                if (Money::lte($d->nominal, 0)) {
                    continue;
                }
                $pair = $pairs[$d->bulan - 1] ?? null; // slot 1..12
                if (! $pair) {
                    continue;
                }
                Budget::create([
                    'tahun' => $pair['tahun'],
                    'bulan' => $pair['bulan'],
                    'kode_coa' => $d->kode_coa,
                    'kode_bagian' => $rec->kode_bagian,
                    'kode_unit' => $scope,
                    'nominal' => $d->nominal,
                ]);
            }

            $rec->update(['status' => 'disetujui']);
        });
    }

    /** Dipanggil mesin saat DITOLAK — di dalam transaksi reject. */
    public function applyRejected(string $idDokumen): void
    {
        $rec = BudgetPengajuan::find((int) $idDokumen);
        if (! $rec || $rec->status !== 'diajukan') {
            return;
        }
        $rec->update(['status' => 'ditolak']);
    }

    /**
     * Kueri pengajuan yang BOLEH DILIHAT seorang pengguna. Rute modul sudah
     * menggerbangi lewat hak "budget"; ini pembatas per-dokumen di atasnya.
     * Penyetuju di rantai membaca lewat /approvals (wewenangnya rantai), jadi
     * di sini cukup: pemohon, anggota bagiannya, Ketua Yayasan, dan admin.
     */
    public function kueriTerlihat(User $user): Builder
    {
        if ($user->is_admin || $user->peringkat_pengajuan === PeringkatPengajuan::KETUA_YAYASAN) {
            return BudgetPengajuan::query();
        }

        return BudgetPengajuan::query()->where(function ($w) use ($user) {
            $w->where('id_pengguna', $user->id_pengguna);
            if ($user->kode_bagian) {
                $w->orWhere('kode_bagian', $user->kode_bagian);
            }
        });
    }

    /** Satu pengajuan + rinciannya, digerbangi visibilitas. */
    public function get(int $id, int $idPengguna): BudgetPengajuan
    {
        $user = User::find($idPengguna);
        if (! $user) {
            throw new AppException(401, 'Pengguna tidak ditemukan.');
        }
        $rec = $this->kueriTerlihat($user)
            ->with(['bagian', 'details' => fn ($q) => $q->orderBy('id')])
            ->find($id);

        if (! $rec) {
            // Bedakan "tidak ada" dari "bukan urusan Anda" agar pesannya jujur.
            throw BudgetPengajuan::whereKey($id)->exists()
                ? new AppException(403, 'Anda tidak berkepentingan atas pengajuan ini.')
                : new AppException(404, 'Pengajuan anggaran tidak ditemukan.');
        }

        return $rec;
    }

    /**
     * Batalkan pengajuan (pemohon atau admin), hanya saat diajukan/ditolak.
     * Instance rantai ikut ditandai "dibatalkan" agar keluar dari inbox penyetuju.
     */
    public function batal(int $id, int $idPengguna): BudgetPengajuan
    {
        $user = User::find($idPengguna);
        if (! $user) {
            throw new AppException(401, 'Pengguna tidak ditemukan.');
        }
        $rec = BudgetPengajuan::find($id);
        if (! $rec) {
            throw new AppException(404, 'Pengajuan anggaran tidak ditemukan.');
        }
        if ($rec->id_pengguna !== $idPengguna && ! $user->is_admin) {
            throw new AppException(403, 'Hanya pemohon atau admin yang dapat membatalkan.');
        }
        if ($rec->status !== 'diajukan' && $rec->status !== 'ditolak') {
            throw new AppException(409, "Pengajuan berstatus \"{$rec->status}\" tidak bisa dibatalkan.");
        }

        DB::transaction(function () use ($rec, $id) {
            $rec->update(['status' => 'dibatalkan']);
            \App\Models\ApprovalInstance::where('jenis_dokumen', self::SUMBER)
                ->where('id_dokumen', (string) $id)
                ->where('status', 'berjalan')
                ->update(['status' => 'dibatalkan']);
        });

        return $rec->refresh();
    }

    /**
     * Ringkasan untuk kartu pratinjau di inbox approver: total per akun (setahun)
     * sudah cukup untuk memutuskan; rincian bulanan tetap bisa dibuka di modul
     * Anggaran oleh yang berhak.
     *
     * @return array<string,mixed>|null
     */
    public function pratinjau(string $idDokumen): ?array
    {
        $rec = BudgetPengajuan::with(['bagian', 'details'])->find((int) $idDokumen);
        if (! $rec) {
            return null;
        }
        $pemohon = User::find($rec->id_pengguna);
        $unit = $rec->kode_unit
            ? \App\Models\BusinessUnit::find($rec->kode_unit)
            : null;

        $perAkun = [];
        foreach ($rec->details as $d) {
            $perAkun[$d->kode_coa] ??= ['nama' => $d->nama_coa, 'total' => '0'];
            $perAkun[$d->kode_coa]['total'] = Money::add($perAkun[$d->kode_coa]['total'], $d->nominal);
        }

        return [
            'ringkas' => [
                ['label' => 'Nomor', 'nilai' => $rec->nomor],
                ['label' => 'Tahun Anggaran', 'nilai' => AnggaranPeriode::labelTahunAnggaran($rec->tahun, $rec->bulan_awal)],
                ['label' => 'Bagian', 'nilai' => $rec->bagian?->nama_bagian ?? $rec->kode_bagian],
                ['label' => 'Unit', 'nilai' => $unit?->nama_unit ?? 'Semua Unit'],
                ['label' => 'Pengaju', 'nilai' => $pemohon?->nama ?? (string) $rec->id_pengguna],
                ['label' => 'Jumlah akun', 'nilai' => (string) count($perAkun)],
                ['label' => 'Total Setahun', 'nilai' => (string) $rec->nominal, 'tipe' => 'uang'],
            ],
            'rincian' => [
                'judul' => 'Anggaran per akun (total setahun)',
                'kolom' => [['label' => 'Akun'], ['label' => 'Total', 'tipe' => 'uang']],
                'baris' => array_map(
                    fn ($kode, $v) => ["{$kode} — {$v['nama']}", $v['total']],
                    array_keys($perAkun),
                    array_values($perAkun),
                ),
            ],
        ];
    }
}
