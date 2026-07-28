<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\MutasiDompet;
use App\Models\Wali;
use App\Services\Modules\DompetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Dompet & Tabungan Santri (wadi'ah/titipan). Tiga dompet: Wali, Santri,
 * Tabungan — semua LIABILITAS (akun titipan). Alur: top-up Dompet Wali →
 * verifikasi keuangan (D Kas/K Titipan) → distribusi ke Dompet/Tabungan Santri.
 * Controller tipis → DompetService.
 */
class DompetController extends Controller
{
    public function __construct(private readonly DompetService $service) {}

    public function index(Request $request): View
    {
        $idWali = $request->integer('id_wali') ?: null;
        $wali = null;
        if ($idWali) {
            try {
                $wali = $this->service->ringkasanWali($idWali);
            } catch (AppException $e) {
                session()->flash('error', $e->getMessage());
            }
        }

        // Top-up menunggu verifikasi (untuk tim keuangan).
        $pending = MutasiDompet::where('jenis', 'topup')->where('status', 'menunggu_verifikasi')
            ->orderByDesc('id')->get();

        // Buku mutasi dompet (riwayat) — 100 terbaru.
        $mutasi = MutasiDompet::with('pencatat')->orderByDesc('id')->limit(100)->get();

        return view('dompet.index', [
            'wali' => $wali,
            'idWali' => $idWali,
            'waliOptions' => Wali::where('status', 'aktif')->orderBy('nama')->get()
                ->mapWithKeys(fn ($w) => [$w->id => "{$w->nama} ({$w->telepon})"])->all(),
            'santriOptions' => \App\Models\Santri::orderBy('nama')->get()
                ->mapWithKeys(fn ($s) => [$s->id => $s->nama.($s->nis ? " ({$s->nis})" : '')])->all(),
            'rekeningOptions' => ['' => '— pilih —'] + BankAccount::where('status', 'aktif')->orderBy('kode_coa')->get()
                ->mapWithKeys(fn ($r) => [$r->kode_coa => "{$r->nama_rekening} ({$r->kode_coa})"])->all(),
            'pending' => $pending,
            'mutasi' => $mutasi,
        ]);
    }

    /** Setor tunai langsung ke Dompet Santri (opsional; bisa dimatikan di Pengaturan Perusahaan). */
    public function topUpSantri(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'id_santri' => ['required', 'integer', 'exists:santri,id'],
            'nominal' => ['required', 'numeric', 'gt:0'],
            'kode_rekening' => ['required', 'string', 'exists:bank_accounts,kode_coa'],
            'tanggal' => ['required', 'date'],
            'keterangan' => ['nullable', 'string'],
        ]);

        try {
            $this->service->topUpSantri($data, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Setoran Dompet Santri dicatat, menunggu verifikasi keuangan.');
    }

    /** Jalankan auto-debet: potong Dompet Wali untuk melunasi tagihan keluarga yang mengizinkan. */
    public function jalankanAutoDebet(Request $request): RedirectResponse
    {
        try {
            $h = (new \App\Services\Modules\AutoDebetService)->jalankan($request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        $pesan = ($h['tagihan'] ?? 0) === 0
            ? 'Tidak ada tagihan yang bisa dipotong — belum ada keluarga yang mengizinkan, atau saldonya kosong.'
            : "{$h['tagihan']} tagihan dari {$h['keluarga']} keluarga dipotong, total Rp ".number_format((float) ($h['total'] ?? 0), 0, ',', '.').'.';

        return back()->with('status', $pesan);
    }

    /** Sajikan berkas bukti mutasi INLINE (bila ada). */
    public function bukti(MutasiDompet $mutasi)
    {
        abort_if(! $mutasi->bukti_path || ! \Illuminate\Support\Facades\Storage::exists($mutasi->bukti_path), 404);

        return response()->file(\Illuminate\Support\Facades\Storage::path($mutasi->bukti_path));
    }

    public function topUp(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'id_wali' => ['required', 'integer', 'exists:wali,id'],
            'nominal' => ['required', 'numeric', 'gt:0'],
            'kode_rekening' => ['required', 'string', 'exists:bank_accounts,kode_coa'],
            'tanggal' => ['required', 'date'],
            'keterangan' => ['nullable', 'string'],
        ]);

        try {
            $this->service->topUp($data, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('dompet.index', ['id_wali' => $data['id_wali']])->with('status', 'Top-up dicatat, menunggu verifikasi keuangan.');
    }

    public function verifikasiTopUp(Request $request, string $id): RedirectResponse
    {
        try {
            $this->service->verifikasiTopUp((int) $id, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Top-up diverifikasi & jurnal diposting.');
    }

    public function tolakTopUp(Request $request, string $id): RedirectResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'max:255']]);

        try {
            $this->service->tolakTopUp((int) $id, $data['alasan'], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Top-up ditolak.');
    }

    public function pindah(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'dari' => ['required', Rule::in(['wali', 'santri'])],
            'ke' => ['required', Rule::in(['santri', 'tabungan'])],
            'id_wali' => ['nullable', 'integer', 'exists:wali,id'],
            'id_santri' => ['nullable', 'integer', 'exists:santri,id'],
            'nominal' => ['required', 'numeric', 'gt:0'],
            'tanggal' => ['required', 'date'],
            'keterangan' => ['nullable', 'string'],
        ]);
        $data['id_wali'] = $data['id_wali'] ? (int) $data['id_wali'] : null;
        $data['id_santri'] = $data['id_santri'] ? (int) $data['id_santri'] : null;

        try {
            $this->service->pindah($data, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('dompet.index', ['id_wali' => $data['id_wali']])->with('status', 'Pemindahan dana berhasil.');
    }

    public function kunci(Request $request, string $idSantri): RedirectResponse
    {
        try {
            $this->service->setKunciTarik((int) $idSantri, $request->boolean('kunci'));
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Status kunci tarik diperbarui.');
    }
}
