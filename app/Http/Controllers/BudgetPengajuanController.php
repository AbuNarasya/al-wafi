<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Services\Ledger\AnggaranPeriode;
use App\Services\Ledger\PeringkatPengajuan;
use App\Services\Modules\ApprovalService;
use App\Services\Modules\BudgetLockService;
use App\Services\Modules\BudgetPengajuanService;
use App\Services\Modules\BudgetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * PENGAJUAN ANGGARAN (§3.c) — jalur non-admin: penyusun bagian mengajukan satu
 * scope (TA + bagian + unit) lewat rantai BUDGET-STD. Anggaran baru tertulis ke
 * tabel `budgets` saat rantai TUNTAS (lihat BudgetPengajuanService::applyApproved).
 *
 * Bagian TIDAK dipilih — diturunkan dari profil pemohon, sama seperti Pengajuan
 * Pembayaran: orang mengajukan atas nama bagiannya sendiri.
 */
class BudgetPengajuanController extends Controller
{
    public function __construct(
        private readonly BudgetPengajuanService $service,
        private readonly ApprovalService $approval,
        private readonly BudgetService $budget,
        private readonly BudgetLockService $lock,
    ) {}

    private function tahunValid(mixed $raw): int
    {
        $n = (int) $raw;

        return ($n >= 2000 && $n <= 2100) ? $n : (int) now()->format('Y');
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        $q = trim((string) $request->query('q', ''));
        $fStatus = trim((string) $request->query('status', ''));

        $rows = $this->service->kueriTerlihat($user)
            ->with('bagian')->withCount('details')
            ->when($q !== '', fn ($query) => $query->where(
                fn ($w) => $w->where('nomor', 'ilike', "%{$q}%")->orWhere('keterangan', 'ilike', "%{$q}%"),
            ))
            ->when($fStatus !== '', fn ($query) => $query->where('status', $fStatus))
            ->orderByDesc('id')
            ->paginate(25)->withQueryString();

        // "Menunggu di" per baris — tahap rantai berjalan + kandidat penyetuju.
        $menunggu = [];
        foreach ($rows as $r) {
            $menunggu[$r->id] = $this->approval->posisi(BudgetPengajuanService::SUMBER, (string) $r->id);
        }

        return view('budget-pengajuan.index', [
            'rows' => $rows,
            'q' => $q,
            'menunggu' => $menunggu,
            // Tombol "Ajukan" hanya bagi yang benar-benar bisa mengajukan —
            // hak modul saja tidak cukup, service menuntut peringkat Staff /
            // Mudir Bagian (lihat catatan staffOrMudirBagian di Navigation).
            'bolehAjukan' => \App\Support\Akses::boleh('budget', 'buat') && in_array(
                $user->peringkat_pengajuan,
                [PeringkatPengajuan::STAFF, PeringkatPengajuan::MUDIR_BAGIAN],
                true,
            ),
            'filter' => ['status' => $fStatus],
            'opsiStatus' => [
                'diajukan' => 'Diajukan', 'disetujui' => 'Disetujui',
                'ditolak' => 'Ditolak', 'dibatalkan' => 'Dibatalkan',
            ],
        ]);
    }

    /**
     * Form ajuan. Grid PRA-ISI dengan anggaran yang sedang berlaku untuk scope
     * itu — bukan kenyamanan belaka: usulan yang disetujui MENGGANTI scope penuh,
     * jadi akun yang tak ikut terkirim akan terhapus. Memulai dari keadaan
     * sekarang membuat "ubah satu akun" tidak diam-diam menghapus sisanya.
     */
    public function create(Request $request): View
    {
        $user = $request->user();
        $tahun = $this->tahunValid($request->query('tahun', now()->format('Y')));
        $kodeUnit = trim((string) $request->query('kode_unit', ''));
        $kodeBagian = (string) ($user->kode_bagian ?? '');

        $grid = $kodeBagian !== '' ? $this->budget->grid($tahun, $kodeBagian, $kodeUnit ?: null) : null;

        return view('budget-pengajuan.create', [
            'tahun' => $tahun,
            'kodeUnit' => $kodeUnit,
            'kodeBagian' => $kodeBagian,
            'namaBagian' => $user->bagian?->nama_bagian ?? $kodeBagian,
            'grid' => $grid,
            'terkunci' => $this->lock->isTerkunci($tahun),
            'units' => BusinessUnit::where('status', 'aktif')->orderBy('kode_unit')->get(['kode_unit', 'nama_unit']),
            'bebanAkun' => CoaDetail::where('kode_coa', 'like', '5%')
                ->orderBy('kode_coa')->get(['kode_coa', 'nama_coa']),
            'labelTa' => AnggaranPeriode::labelTahunAnggaran($tahun, AnggaranPeriode::bulanAwalAnggaran()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = json_decode((string) $request->input('payload'), true);
        if (! is_array($data)) {
            return back()->with('error', 'Data anggaran tidak valid.');
        }
        $tahun = $this->tahunValid($data['tahun'] ?? null);
        $kodeUnit = trim((string) ($data['kode_unit'] ?? ''));

        try {
            $rec = $this->service->create([
                'tahun' => $tahun,
                'kode_unit' => $kodeUnit ?: null,
                'keterangan' => trim((string) $request->input('keterangan')) ?: null,
                'items' => array_map(fn ($it) => [
                    'kode_coa' => (string) ($it['kode_coa'] ?? ''),
                    'bulan' => (int) ($it['bulan'] ?? 0),
                    'nominal' => (string) ($it['nominal'] ?? '0'),
                ], is_array($data['items'] ?? null) ? $data['items'] : []),
            ], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('budget.pengajuan.show', $rec->id)
            ->with('status', "Pengajuan anggaran {$rec->nomor} terkirim ke rantai persetujuan.");
    }

    public function show(Request $request, int $id): View
    {
        try {
            $rec = $this->service->get($id, $request->user()->id_pengguna);
        } catch (AppException $e) {
            abort($e->status, $e->getMessage());
        }

        $pairs = AnggaranPeriode::bulanTahunAnggaran($rec->tahun, $rec->bulan_awal);

        // Rincian dibentuk ulang jadi grid akun × 12 slot supaya terbaca seperti
        // halaman anggaran, bukan daftar panjang baris-per-bulan.
        $perAkun = [];
        foreach ($rec->details as $d) {
            $perAkun[$d->kode_coa] ??= ['kode_coa' => $d->kode_coa, 'nama_coa' => $d->nama_coa, 'bulanan' => array_fill(0, 12, '0')];
            if ($d->bulan >= 1 && $d->bulan <= 12) {
                $perAkun[$d->kode_coa]['bulanan'][$d->bulan - 1] = (string) $d->nominal;
            }
        }
        ksort($perAkun);

        return view('budget-pengajuan.show', [
            'rec' => $rec,
            'baris' => array_values($perAkun),
            'bulanUrut' => $pairs,
            'labelTa' => AnggaranPeriode::labelTahunAnggaran($rec->tahun, $rec->bulan_awal),
            'unit' => $rec->kode_unit ? BusinessUnit::find($rec->kode_unit) : null,
            'timeline' => $this->approval->timeline(BudgetPengajuanService::SUMBER, (string) $rec->id),
            'bolehBatal' => in_array($rec->status, ['diajukan', 'ditolak'], true)
                && ($request->user()->is_admin || $rec->id_pengguna === $request->user()->id_pengguna),
        ]);
    }

    public function batal(Request $request, int $id): RedirectResponse
    {
        try {
            $rec = $this->service->batal($id, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('budget.pengajuan.show', $rec->id)
            ->with('status', "Pengajuan {$rec->nomor} dibatalkan.");
    }
}
