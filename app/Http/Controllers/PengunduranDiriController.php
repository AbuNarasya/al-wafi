<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\DokumenPengajuanKeluar;
use App\Models\PengajuanKeluar;
use App\Services\Modules\PengunduranDiriService;
use App\Support\Akses;
use App\Support\Unggahan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Layar staf PENGUNDURAN DIRI santri aktif (21 Okt 2026) — lihat PengunduranDiriService untuk aturannya. */
class PengunduranDiriController extends Controller
{
    public function __construct(private readonly PengunduranDiriService $svc) {}

    public function index(Request $request): View
    {
        $u = $request->user();
        $tab = $request->query('tab', 'tindakan');
        $semua = PengajuanKeluar::with(['santri:id,nama,nis,kode_jenjang,id_kelas', 'santri.kelas:id,nama,id_wali_kelas', 'santri.jenjang:kode,nama,id_kepala_sekolah'])
            ->orderByDesc('id')->get();

        $perlu = $semua->filter(fn (PengajuanKeluar $p) => $this->perluTindakan($p, $u))->values();
        $daftar = match ($tab) {
            'tindakan' => $perlu,
            'siap' => $semua->where('status', 'siap_dikeluarkan')->values(),
            'berjalan' => $semua->whereIn('status', PengajuanKeluar::BERJALAN)->values(),
            default => $semua,
        };

        return view('pengunduran-diri.index', ['tab' => $tab, 'daftar' => $daftar, 'jumlahPerlu' => $perlu->count(),
            'jumlahSiap' => $semua->where('status', 'siap_dikeluarkan')->count(), 'bolehKeluarkan' => Akses::boleh('clearance-admin', 'ubah')]);
    }

    public function show(Request $request, PengajuanKeluar $pengajuan): View
    {
        $p = $pengajuan->load(['santri.kelas.waliKelas', 'santri.jenjang', 'wali', 'dokumen']);
        $u = $request->user();

        return view('pengunduran-diri.show', [
            'p' => $p, 'keuangan' => $this->svc->keuangan($p->santri), 'maksLabel' => Unggahan::maksLabel(),
            'bolehWaliKelas' => $this->svc->bolehWaliKelas($u, $p), 'bolehKepsek' => $this->svc->bolehKepalaSekolah($u, $p),
            'bolehClearance' => collect(PengunduranDiriService::MODUL_CLEARANCE)->map(fn ($m) => Akses::bolehUntuk($u, $m, 'buat'))->all(),
            'bolehKeluarkan' => Akses::bolehUntuk($u, 'clearance-admin', 'ubah'),
        ]);
    }

    /** Diajukan staf atas nama wali, dari halaman santri (santri aktif tak lagi bisa dikeluarkan langsung). */
    public function ajukan(Request $request, int $santri): RedirectResponse
    {
        $d = $request->validate(['alasan' => ['required', 'string', 'max:2000'], 'rencana_tanggal' => ['nullable', 'date'], 'surat' => Unggahan::aturan()]);
        $s = \App\Models\Santri::findOrFail($santri);
        try {
            $p = $this->svc->ajukanOlehStaf($s, $request->user(), $d['alasan'], $d['rencana_tanggal'] ?? null, $request->file('surat'));
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('pengunduran_diri.show', $p->id)->with('status', "Pengajuan {$p->nomor} dikirim ke wali kelas.");
    }

    public function verifikasi(Request $request, PengajuanKeluar $pengajuan): RedirectResponse
    {
        $d = $request->validate(['keputusan' => ['required', Rule::in(['lanjut', 'kembalikan'])], 'catatan' => ['nullable', 'string', 'max:2000']]);

        return $this->coba(fn () => $this->svc->verifikasiWaliKelas($pengajuan, $request->user(), $d['keputusan'] === 'lanjut', $d['catatan'] ?? null),
            $d['keputusan'] === 'lanjut' ? 'Diverifikasi — diteruskan ke kepala sekolah.' : 'Pengajuan dikembalikan ke wali.');
    }

    public function putuskan(Request $request, PengajuanKeluar $pengajuan): RedirectResponse
    {
        $d = $request->validate(['keputusan' => ['required', Rule::in(['ya', 'tidak'])], 'catatan' => ['nullable', 'string', 'max:2000']]);

        return $this->coba(fn () => $this->svc->putuskanKepalaSekolah($pengajuan, $request->user(), $d['keputusan'] === 'ya', $d['catatan'] ?? null),
            $d['keputusan'] === 'ya' ? 'Disetujui — masuk proses clearance.' : 'Pengajuan tidak disetujui.');
    }

    public function clearance(Request $request, PengajuanKeluar $pengajuan, string $pihak): RedirectResponse
    {
        $d = $request->validate(['catatan' => ['nullable', 'string', 'max:2000']]);

        return $this->coba(fn () => $this->svc->clearance($pengajuan, $pihak, $request->user(), $d['catatan'] ?? null), 'Clearance dicatat.');
    }

    public function unggah(Request $request, PengajuanKeluar $pengajuan): RedirectResponse
    {
        abort_unless(Akses::boleh('clearance-admin', 'buat'), 403);
        $d = $request->validate(['jenis' => ['required', Rule::in(['surat_mutasi', 'surat_keputusan', 'lainnya'])],
            'keterangan' => ['nullable', 'string', 'max:255'], 'berkas' => Unggahan::aturan()]);

        return $this->coba(fn () => $this->svc->unggahDokumen($pengajuan, $d['jenis'], $request->file('berkas'), $d['keterangan'] ?? null, $request->user()->id_pengguna),
            'Dokumen diunggah.');
    }

    public function keluarkan(Request $request): RedirectResponse
    {
        $ids = $request->validate(['pengajuan' => ['required', 'array', 'min:1'], 'pengajuan.*' => ['integer']], ['pengajuan.required' => 'Pilih minimal satu santri.'])['pengajuan'];
        $ok = 0;
        $gagal = [];
        foreach (PengajuanKeluar::with('santri:id,nama')->whereIn('id', $ids)->get() as $p) {
            try {
                $this->svc->keluarkan($p, $request->user());
                $ok++;
            } catch (AppException $e) {
                $gagal[] = "{$p->santri?->nama}: {$e->getMessage()}";
            }
        }
        $r = back()->with('status', "{$ok} santri dikeluarkan.");

        return $gagal ? $r->with('error', implode(' · ', $gagal)) : $r;
    }

    public function dapodik(Request $request, PengajuanKeluar $pengajuan): RedirectResponse
    {
        abort_unless(Akses::boleh('clearance-admin', 'ubah'), 403);

        return $this->coba(fn () => $this->svc->tandaiDapodik($pengajuan, $request->user()), 'Ditandai sudah diproses di Dapodik.');
    }

    public function dokumen(DokumenPengajuanKeluar $dokumen): StreamedResponse
    {
        return \App\Support\Unggahan::sajikan('local', $dokumen->path, $dokumen->nama_asli);
    }

    private function perluTindakan(PengajuanKeluar $p, $u): bool
    {
        return match ($p->status) {
            'menunggu_wali_kelas' => $this->svc->bolehWaliKelas($u, $p),
            'menunggu_kepala_sekolah' => $this->svc->bolehKepalaSekolah($u, $p),
            'clearance' => collect(PengunduranDiriService::MODUL_CLEARANCE)->contains(fn ($m, $pihak) => ! $p->{"{$pihak}_pada"} && Akses::bolehUntuk($u, $m, 'buat')),
            'siap_dikeluarkan' => Akses::bolehUntuk($u, 'clearance-admin', 'ubah'),
            default => false,
        };
    }

    private function coba(\Closure $aksi, string $pesan): RedirectResponse
    {
        try {
            $aksi();
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('status', $pesan);
    }
}
