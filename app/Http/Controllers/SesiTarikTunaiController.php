<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\Jenjang;
use App\Models\Santri;
use App\Models\SesiTarikTunai;
use App\Services\Modules\PenarikanTunaiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Sesi penarikan tunai kegiatan — layar KEUANGAN. Hak `sesi-tarik-tunai`: lihat / buat /
 * ubah (jadwal, peserta, saklar buka) / hapus (batal). Logika: [[PenarikanTunaiService]].
 */
class SesiTarikTunaiController extends Controller
{
    public function __construct(private readonly PenarikanTunaiService $service) {}

    public function index(): View
    {
        return view('tarik-tunai.index', [
            'rows' => SesiTarikTunai::withCount('santri')->orderByDesc('id_sesi')->paginate(25),
            'opsiJenjang' => Jenjang::orderBy('urutan')->orderBy('kode')->pluck('nama', 'kode')->all(),
            'rekening' => $this->rekening(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validasi($request) + $request->validate([
            'kode_jenjang' => ['required', 'string', 'exists:jenjang,kode'],
            'tingkat' => ['nullable', 'array'], 'tingkat.*' => ['integer', 'min:1', 'max:12'],
        ]);

        try {
            $peserta = $this->service->calonPeserta($data['kode_jenjang'], $data['tingkat'] ?? [])->all();
            $sesi = $this->service->buatSesi($data, $peserta, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('tarik_tunai.show', $sesi->id_sesi)
            ->with('status', "Sesi {$sesi->nomor} dibuat dengan ".count($peserta).' peserta. Periksa pesertanya, lalu buka untuk wali.');
    }

    public function show(SesiTarikTunai $sesi): View
    {
        $sesi->load(['bank', 'kas', 'pembuat']);

        return view('tarik-tunai.show', [
            'sesi' => $sesi,
            'rekap' => $this->service->rekap($sesi),
            'peserta' => $sesi->santri()->with(['jenjang', 'dompet'])->orderBy('nama')->get(),
            'pengajuan' => $sesi->pengajuan()->with(['santri:id,nama,nis', 'wali', 'pemutus', 'sesi'])->orderBy('id')->get()->keyBy('id_santri'),
            'rekening' => $this->rekening(),
        ]);
    }

    public function update(Request $request, SesiTarikTunai $sesi): RedirectResponse
    {
        $data = $this->validasi($request);

        return $this->jalankan(fn () => $this->service->ubahSesi($sesi->id_sesi, $data), 'Jadwal & batas sesi disimpan.');
    }

    public function dibuka(Request $request, SesiTarikTunai $sesi): RedirectResponse
    {
        $buka = $request->boolean('dibuka');

        return $this->jalankan(fn () => $this->service->aturDibuka($sesi->id_sesi, $buka),
            $buka ? 'Sesi dibuka — wali peserta kini bisa mengajukan dari portal.' : 'Sesi ditutup untuk wali.');
    }

    /** Tambah peserta lewat NIS (dipisah koma/spasi/baris baru). */
    public function tambahPeserta(Request $request, SesiTarikTunai $sesi): RedirectResponse
    {
        $data = $request->validate(['nis' => ['required', 'string', 'max:5000']]);
        $nis = array_values(array_filter(preg_split('/[\s,;]+/', $data['nis'])));
        $id = Santri::whereIn('nis', $nis)->pluck('id')->all();

        try {
            $baru = $this->service->tambahPeserta($sesi->id_sesi, $id);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }
        $takKetemu = count($nis) - count($id);

        return back()->with('status', "{$baru} peserta ditambahkan.".($takKetemu > 0 ? " {$takKetemu} NIS tidak ditemukan." : ''));
    }

    public function hapusPeserta(SesiTarikTunai $sesi, Santri $santri): RedirectResponse
    {
        return $this->jalankan(fn () => $this->service->hapusPeserta($sesi->id_sesi, $santri->id), "{$santri->nama} dikeluarkan dari peserta.");
    }

    public function batal(Request $request, SesiTarikTunai $sesi): RedirectResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'max:255']]);

        return $this->jalankan(fn () => $this->service->batalSesi($sesi->id_sesi, $data['alasan'], $request->user()->id_pengguna),
            "Sesi {$sesi->nomor} dibatalkan — pengajuan yang masih hidup ikut batal dan wali dikabari.");
    }

    private function validasi(Request $request): array
    {
        return $request->validate([
            'nama' => ['required', 'string', 'max:150'],
            'keterangan' => ['nullable', 'string', 'max:1000'],
            'batas_pengajuan' => ['required', 'date'],
            'batas_persetujuan' => ['required', 'date'],
            'tanggal_ambil' => ['required', 'date'],
            'tanggal_distribusi' => ['required', 'date'],
            'maks_per_santri' => ['required', 'numeric', 'gt:0'],
            'kode_coa_bank' => ['required', 'string', 'exists:bank_accounts,kode_coa'],
            'kode_coa_kas' => ['required', 'string', 'exists:bank_accounts,kode_coa'],
        ]);
    }

    private function jalankan(\Closure $aksi, string $pesan): RedirectResponse
    {
        try {
            $aksi();
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('status', $pesan);
    }

    private function rekening(): array
    {
        return BankAccount::where('status', 'aktif')->orderBy('kode_coa')->get()
            ->mapWithKeys(fn ($b) => [$b->kode_coa => "{$b->kode_coa} — {$b->nama_rekening}"])->all();
    }
}
