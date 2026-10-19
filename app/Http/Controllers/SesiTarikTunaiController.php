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

    // ---- E2: ambil dana, distribusi, selesai ----

    /** Lembar rekap untuk slip penarikan bank (bisa dicetak). */
    public function rekap(SesiTarikTunai $sesi): View
    {
        return view('tarik-tunai.rekap', ['sesi' => $sesi->load('bank', 'kas'), ...$this->service->rekapAmbil($sesi)]);
    }

    public function ambil(Request $request, SesiTarikTunai $sesi): RedirectResponse
    {
        return $this->jalankan(fn () => $this->service->ambilDana($sesi->id_sesi, $request->user()->id_pengguna),
            'Dana dicatat sudah diambil — jurnal D Kas Tunai Kegiatan / K Bank dibukukan. Sesi masuk tahap distribusi.');
    }

    /** Layar distribusi: tempel kartu → tampil nama & nominal → Serahkan. */
    public function distribusi(Request $request, SesiTarikTunai $sesi): View|RedirectResponse
    {
        if ($sesi->status !== 'dana_diambil') {
            return redirect()->route('tarik_tunai.show', $sesi->id_sesi)->with('error', 'Distribusi hanya sesudah dana diambil dan sebelum sesi diselesaikan.');
        }
        $siap = null;
        $galat = null;
        if ($request->filled('uid')) {
            try {
                $siap = $this->service->pengajuanUntukKartu($sesi->id_sesi, $request->query('uid'));
            } catch (AppException $e) {
                $galat = $e->getMessage();
            }
        }
        $semua = $sesi->pengajuan()->with(['santri:id,nama,nis', 'penyerah'])->whereIn('status', ['disetujui', 'diserahkan'])->get()->sortBy('santri.nama');
        $diserahkan = $semua->where('status', 'diserahkan');

        return view('tarik-tunai.distribusi', [
            'sesi' => $sesi, 'siap' => $siap, 'galat' => $galat,
            'belum' => $semua->where('status', 'disetujui')->values(),
            'sudah' => $diserahkan->sortByDesc('diserahkan_pada')->values(),
            'totalSerah' => $diserahkan->reduce(fn ($t, $p) => \App\Support\Money::add($t, $p->nominal_disetujui), '0'),
        ]);
    }

    public function serah(Request $request, SesiTarikTunai $sesi): RedirectResponse
    {
        $data = $request->validate(['uid' => ['required', 'string', 'max:100']]);
        try {
            $p = $this->service->serahLewatKartu($sesi->id_sesi, $data['uid'], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return redirect()->route('tarik_tunai.distribusi', $sesi->id_sesi)->with('error', $e->getMessage());
        }

        return redirect()->route('tarik_tunai.distribusi', $sesi->id_sesi)
            ->with('status', "Rp ".number_format((float) $p->nominal_disetujui, 0, ',', '.')." diserahkan kepada {$p->santri->nama}.");
    }

    public function serahManual(Request $request, SesiTarikTunai $sesi): RedirectResponse
    {
        $data = $request->validate(['id_pengajuan' => ['required', 'integer'], 'alasan' => ['required', 'string', 'max:255']]);
        $p = $sesi->pengajuan()->findOrFail($data['id_pengajuan']);
        try {
            $p = $this->service->serahManual($p->id, $data['alasan'], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "Rp ".number_format((float) $p->nominal_disetujui, 0, ',', '.')." diserahkan kepada {$p->santri->nama} (tanpa kartu).");
    }

    public function batalSerah(Request $request, SesiTarikTunai $sesi, int $id): RedirectResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'max:255']]);
        $p = $sesi->pengajuan()->findOrFail($id);

        return $this->jalankan(fn () => $this->service->batalSerah($p->id, $data['alasan'], $request->user()->id_pengguna),
            'Penyerahan dibatalkan — uang saku dikembalikan ke dompet santri.');
    }

    public function selesai(Request $request, SesiTarikTunai $sesi): RedirectResponse
    {
        $data = $request->validate(['uang_fisik' => ['required', 'numeric', 'min:0']]);

        try {
            $sesi = $this->service->selesaikan($sesi->id_sesi, (string) $data['uang_fisik'], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('tarik_tunai.show', $sesi->id_sesi)->with('status', 'Sesi selesai. Jurnal penutup dibukukan; setorkan sisa Rp '
            .number_format((float) $sesi->sisa_disetor, 0, ',', '.').' ke rekening bank.');
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
