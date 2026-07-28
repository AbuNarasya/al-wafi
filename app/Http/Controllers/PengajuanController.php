<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Http\Requests\PengajuanRequest;
use App\Models\ApprovalInstance;
use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\PengajuanPembayaran;
use App\Services\Modules\PengajuanPembayaranService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Pengajuan Pembayaran (§4) — jenis "pembayaran" (accrual). Alur: Staff membuat
 * → rantai persetujuan (approval) → verifikasi keuangan (posting) → dibayar Kas
 * Keluar. Controller tipis → PengajuanPembayaranService.
 */
class PengajuanController extends Controller
{
    public function __construct(
        private readonly PengajuanPembayaranService $service,
        private readonly \App\Services\Modules\ApprovalService $approval,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $q = trim((string) $request->query('q', ''));
        $fJenis = trim((string) $request->query('jenis', ''));
        $fStatus = trim((string) $request->query('status', ''));

        $rows = PengajuanPembayaran::query()
            ->with('bagian')
            // Visibilitas: admin & tim keuangan lihat semua; selain itu pengajuan
            // milik sendiri atau dari bagiannya (approver bagian).
            ->when(! ($user->is_admin || $user->tim_keuangan), fn ($query) => $query->where(
                fn ($w) => $w->where('id_pengguna', $user->id_pengguna)->orWhere('kode_bagian', $user->kode_bagian),
            ))
            ->when($q !== '', fn ($query) => $query->where(
                fn ($w) => $w->where('nomor', 'ilike', "%{$q}%")->orWhere('keterangan', 'ilike', "%{$q}%"),
            ))
            ->when($fJenis !== '', fn ($query) => $query->where('jenis', $fJenis))
            ->when($fStatus !== '', fn ($query) => $query->where('status', $fStatus))
            ->orderByDesc('tanggal')->orderByDesc('id')
            ->paginate(25)->withQueryString();

        // "Menunggu di" per baris: tahap rantai berjalan + kandidat penyetuju;
        // bila rantai sudah selesai tapi belum diposting → menunggu Verifikasi Keuangan.
        $menunggu = [];
        $keuanganNames = null;
        foreach ($rows as $r) {
            $pos = $this->approval->posisi(PengajuanPembayaranService::SUMBER, (string) $r->id);
            if (! $pos && $r->status === 'diajukan') {
                $inst = ApprovalInstance::where('jenis_dokumen', PengajuanPembayaranService::SUMBER)
                    ->where('id_dokumen', (string) $r->id)->first();
                if ($inst && $inst->status === 'disetujui' && ! $inst->posted) {
                    $keuanganNames ??= \App\Models\User::where('tim_keuangan', true)->where('status', 'aktif')->orderBy('nama')->pluck('nama')->all();
                    $pos = ['nama_tahap' => 'Verifikasi Keuangan', 'kandidat' => $keuanganNames];
                }
            }
            $menunggu[$r->id] = $pos;
        }

        return view('pengajuan.index', [
            'rows' => $rows,
            'q' => $q,
            'menunggu' => $menunggu,
            'filter' => ['jenis' => $fJenis, 'status' => $fStatus],
            'opsiJenis' => [
                'pembayaran' => 'Pembayaran', 'uang_muka' => 'Uang Muka',
                'penyelesaian_uang_muka' => 'Penyelesaian UM',
            ],
            'opsiStatus' => [
                'diajukan' => 'Diajukan', 'diverifikasi' => 'Diverifikasi', 'diposting' => 'Diposting',
                'lunas' => 'Lunas', 'ditolak' => 'Ditolak', 'void' => 'Void',
            ],
        ]);
    }

    public function create(): View
    {
        return view('pengajuan.create', $this->opsi('pembayaran'));
    }

    /** #5: Buat Pengajuan Uang Muka — rincian akun Aset (kelompok 1). */
    public function createUangMuka(): View
    {
        return view('pengajuan.create', $this->opsi('uang_muka'));
    }

    /** #6: Buat Penyelesaian Uang Muka — pilih UM outstanding milik sendiri. */
    public function createPenyelesaian(Request $request): View
    {
        return view('pengajuan.create', $this->opsi('penyelesaian_uang_muka', $request->user()->id_pengguna));
    }

    /** #7: Outstanding Uang Muka — daftar UM milik pemohon yang belum selesai. */
    public function outstandingUangMuka(Request $request): View
    {
        return view('pengajuan.outstanding-uang-muka', [
            'rows' => $this->service->uangMukaSaya($request->user()->id_pengguna),
        ]);
    }

    public function store(PengajuanRequest $request): RedirectResponse
    {
        $jenis = $request->input('jenis', 'pembayaran');
        if (! in_array($jenis, ['pembayaran', 'uang_muka', 'penyelesaian_uang_muka'], true)) {
            $jenis = 'pembayaran';
        }
        try {
            $rec = $this->service->create([
                'jenis' => $jenis,
                'tanggal' => $request->input('tanggal'),
                'keterangan' => $request->input('keterangan'),
                'referensi' => $request->input('referensi'),
                'id_uang_muka' => $jenis === 'penyelesaian_uang_muka' ? (int) $request->input('id_uang_muka') : null,
                'details' => $request->details(),
            ], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('pengajuan.show', $rec->id)->with('status', "Pengajuan {$rec->nomor} berhasil diajukan.");
    }

    /** Perbaiki pengajuan yang ditolak — form yang sama dengan Buat, mode edit. */
    public function edit(Request $request, int $id): View
    {
        $rec = PengajuanPembayaran::with('details')->findOrFail($id);
        $opsi = $this->opsi($rec->jenis, $request->user()->id_pengguna);

        return view('pengajuan.create', array_merge($opsi, ['pengajuan' => $rec]));
    }

    /** Simpan perbaikan (status tetap 'ditolak'; ajukan ulang terpisah). */
    public function update(PengajuanRequest $request, int $id): RedirectResponse
    {
        $rec = PengajuanPembayaran::findOrFail($id);
        try {
            $this->service->update($id, [
                'jenis' => $rec->jenis,
                'tanggal' => $request->input('tanggal'),
                'keterangan' => $request->input('keterangan'),
                'referensi' => $request->input('referensi'),
                'details' => $request->details(),
            ], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('pengajuan.index')
            ->with('status', "Perbaikan pengajuan {$rec->nomor} disimpan. Tekan \"Ajukan Ulang\" untuk memulai rantai persetujuan.");
    }

    /** Ajukan ulang pengajuan yang ditolak (memulai rantai persetujuan lagi). */
    public function ajukanUlang(Request $request, int $id): RedirectResponse
    {
        try {
            $rec = $this->service->ajukanUlang($id, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('pengajuan.index')->with('status', "Pengajuan {$rec->nomor} berhasil diajukan ulang.");
    }

    /** Opsi form untuk sebuah jenis pengajuan. */
    private function opsi(string $jenis, ?int $idPengguna = null): array
    {
        // Uang muka → hanya akun Aset (kelompok 1); lainnya semua akun aktif.
        $coa = CoaDetail::where('status', 'aktif')
            ->when($jenis === 'uang_muka', fn ($q) => $q->where('kode_coa', 'like', '1%'))
            ->orderBy('kode_coa')->get();

        return [
            'jenis' => $jenis,
            'nomorPreview' => $this->service->previewNomor($jenis),
            'coaOptions' => $coa->map(fn ($c) => ['v' => $c->kode_coa, 'l' => "{$c->kode_coa} — {$c->nama_coa}"])->values()->all(),
            'unitOptions' => BusinessUnit::where('status', 'aktif')->orderBy('kode_unit')->get()
                ->map(fn ($u) => ['v' => $u->kode_unit, 'l' => "{$u->kode_unit} — {$u->nama_unit}"])->values()->all(),
            'uangMukaList' => $jenis === 'penyelesaian_uang_muka' && $idPengguna
                ? collect($this->service->uangMukaSaya($idPengguna))
                : collect(),
        ];
    }

    public function show(int $id): View
    {
        $rec = PengajuanPembayaran::with(['details', 'bagian', 'pemohon'])->findOrFail($id);
        $instance = ApprovalInstance::with(['logs' => fn ($q) => $q->orderBy('waktu')])
            ->where('jenis_dokumen', PengajuanPembayaranService::SUMBER)
            ->where('id_dokumen', (string) $id)->first();

        // Akun hutang untuk verifikasi pembayaran: kelompok 2 (liabilitas), aktif.
        $hutangOptions = CoaDetail::where('status', 'aktif')->where('kode_coa', 'like', '2%')->orderBy('kode_coa')->get()
            ->mapWithKeys(fn ($c) => [$c->kode_coa => "{$c->kode_coa} — {$c->nama_coa}"])->all();

        // Kas/rekening penampung selisih untuk verifikasi penyelesaian uang muka.
        $rekeningOptions = BankAccount::where('status', 'aktif')->with('coa')->orderBy('kode_coa')->get()
            ->mapWithKeys(fn ($r) => [$r->kode_coa => "{$r->nama_rekening} ({$r->kode_coa})"])->all();

        // Semua akun aktif — untuk "Koreksi Akun" (§4.f) oleh keuangan.
        $coaOptions = CoaDetail::where('status', 'aktif')->orderBy('kode_coa')->get()
            ->mapWithKeys(fn ($c) => [$c->kode_coa => "{$c->kode_coa} — {$c->nama_coa}"])->all();

        // Data tampilan rantai persetujuan (Sekarang Menunggu + Tahap + Riwayat).
        $timeline = $this->approval->timeline(PengajuanPembayaranService::SUMBER, (string) $id);

        return view('pengajuan.show', compact('rec', 'instance', 'hutangOptions', 'rekeningOptions', 'coaOptions', 'timeline'));
    }

    /** Lembar cetak — HANYA untuk pengajuan yang sudah disetujui SELURUH approver. */
    public function cetak(int $id): View
    {
        $rec = PengajuanPembayaran::with(['details', 'bagian', 'pemohon'])->findOrFail($id);
        $instance = ApprovalInstance::where('jenis_dokumen', PengajuanPembayaranService::SUMBER)
            ->where('id_dokumen', (string) $id)->first();

        // Surat sakti dicegah: cetakan hanya sah bila rantai persetujuan TUNTAS.
        abort_unless(
            $instance && $instance->status === 'disetujui' && $rec->status !== 'void',
            403,
            'Pengajuan ini belum disetujui seluruh approver, jadi belum dapat dicetak.',
        );

        $timeline = $this->approval->timeline(PengajuanPembayaranService::SUMBER, (string) $id);
        $company = \App\Models\CompanySettings::first();

        return view('pengajuan.print', compact('rec', 'timeline', 'company'));
    }

    public function verifikasi(Request $request, int $id): RedirectResponse
    {
        $rec = PengajuanPembayaran::with('details')->findOrFail($id);

        // Field verifikasi berbeda per jenis: pembayaran (accrual) butuh akun
        // hutang; penyelesaian butuh kas/rekening penampung selisih; uang muka
        // (cash basis) tak butuh keduanya — Kas Keluar yang memposting nanti.
        $rules = [
            'catatan' => ['nullable', 'string'],
            'koreksi' => ['nullable', 'array'],
            'koreksi.*' => ['nullable', 'string', 'exists:coa_detail,kode_coa'],
        ];
        if ($rec->jenis === 'pembayaran') {
            $rules['kode_coa_hutang'] = ['required', 'string', 'exists:coa_detail,kode_coa'];
        } elseif ($rec->jenis === 'penyelesaian_uang_muka') {
            $rules['kode_rekening'] = ['required', 'string', 'exists:bank_accounts,kode_coa'];
        }
        $data = $request->validate($rules);

        // §4.f — koreksi akun per baris (bila keuangan mengubahnya) DULU, lalu verifikasi.
        $koreksi = [];
        foreach ($request->input('koreksi', []) as $idDetail => $kodeCoa) {
            if (! $kodeCoa) {
                continue;
            }
            $line = $rec->details->firstWhere('id', (int) $idDetail);
            if ($line && $line->kode_coa !== $kodeCoa) {
                $koreksi[] = ['id_detail' => (int) $idDetail, 'kode_coa' => $kodeCoa];
            }
        }

        try {
            if ($koreksi !== []) {
                $this->service->koreksiAkun($id, $koreksi, $request->user()->id_pengguna, $data['catatan'] ?? null);
            }
            $this->service->verifikasi(
                $id,
                $data['kode_coa_hutang'] ?? null,
                $request->user()->id_pengguna,
                $data['catatan'] ?? null,
                $data['kode_rekening'] ?? null,
            );
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        $pesan = $rec->jenis === 'uang_muka'
            ? 'Uang muka diverifikasi (siap dibayar lewat Kas Keluar).'
            : 'Pengajuan diverifikasi & diposting.';

        return redirect()->route('pengajuan.show', $id)->with('status', $pesan);
    }

    public function void(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'max:255']]);

        try {
            $this->service->void($id, $data['alasan'], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('pengajuan.index')->with('status', 'Pengajuan dibatalkan.');
    }
}
