<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Services\Modules\PemakaianLainService;
use App\Services\Modules\TagihanLainService;
use App\Support\Referensi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * SETORAN PEMAKAIAN — layar yang dipakai TIAP HARI oleh petugas laundry.
 *
 * Haknya (`setoran-laundry`) sengaja terpisah dari `tagihan-lain`: mencatat
 * timbangan bukan menerbitkan uang, dan petugas laundry tak perlu wewenang
 * menagih siapa pun. Penerbitan periodenya tetap milik `tagihan-lain`.
 */
class SetoranPemakaianController extends Controller
{
    public function __construct(private readonly PemakaianLainService $service = new PemakaianLainService) {}

    // ---- Matriks tarif layanan ----

    public function tarif(): View
    {
        return view('setoran-pemakaian.tarif', ['grid' => $this->service->gridTarif()]);
    }

    public function simpanTarif(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'baris' => ['array'],
            'baris.*.tarif_satuan' => ['nullable', 'numeric', 'min:0'],
            'baris.*.nama_satuan' => ['nullable', 'string', 'max:20'],
            'baris.*.kuota_gratis' => ['nullable', 'numeric', 'min:0'],
        ]);

        $hasil = $this->service->simpanGridTarif($data['baris'] ?? []);

        $pesan = "Tarif layanan tersimpan — {$hasil['tersimpan']} layanan berlaku";
        $pesan .= $hasil['dihapus'] > 0 ? ", {$hasil['dihapus']} dikosongkan." : '.';

        return redirect()->route('setoran_pemakaian.tarif')->with('status', $pesan);
    }

    // ---- Pencatatan harian ----

    public function index(Request $request): View
    {
        $daftar = $this->service->jenisPemakaian();
        $kode = trim((string) $request->query('jenis', '')) ?: (string) $daftar->first()?->kode;
        $jenis = $kode !== '' ? $daftar->firstWhere('kode', $kode) : null;

        // Rekap & riwayat hanya bisa disusun bila layanannya lengkap; jenis yang
        // belum bertarif melempar dari service, dan itu ditangkap jadi pesan.
        $rekap = [];
        $tarif = null;
        $galat = null;
        if ($jenis) {
            try {
                $tarif = $this->service->tarif($jenis);
                $rekap = $this->service->rekap($jenis->kode);
            } catch (AppException $e) {
                $galat = $e->getMessage();
            }
        }

        return view('setoran-pemakaian.index', [
            'opsiJenis' => $daftar->mapWithKeys(fn ($j) => [$j->kode => $j->nama])->all(),
            'kodeJenis' => $kode,
            'jenis' => $jenis,
            'tarif' => $tarif,
            'rekap' => $rekap,
            'galat' => $galat,
            // Hanya santri jenjang layanan itu — mencatat santri jenjang lain
            // akan menggerus kuota yang bukan miliknya.
            'santriAktif' => $jenis?->kode_jenjang
                ? Referensi::santri('aktif', $jenis->kode_jenjang)
                : Referensi::santri('aktif'),
            'riwayat' => $jenis ? \App\Models\SetoranPemakaian::where('kode_jenis', $jenis->kode)
                ->with(['santri:id,nis,nama', 'pencatat:id_pengguna,nama'])
                ->orderByDesc('tanggal')->orderByDesc('id')->limit(30)->get() : collect(),
        ]);
    }

    public function catat(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kode_jenis' => ['required', 'string'],
            'id_santri' => ['required', 'integer'],
            'tanggal' => ['required', 'date'],
            'kuantitas' => ['required', 'numeric', 'gt:0'],
            'catatan' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $s = $this->service->catat($data, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('setoran_pemakaian.index', ['jenis' => $data['kode_jenis']])
            ->with('status', "Setoran {$s->santri?->nama} tercatat.");
    }

    public function hapus(Request $request, int $id): RedirectResponse
    {
        try {
            $s = $this->service->hapusSetoran($id);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('setoran_pemakaian.index', ['jenis' => $s->kode_jenis])
            ->with('status', 'Setoran dihapus.');
    }

    public function terbitkan(Request $request, TagihanLainService $tagihan): RedirectResponse
    {
        $data = $request->validate([
            'kode_jenis' => ['required', 'string'],
            'periode' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'tanggal' => ['required', 'date'],
            'jatuh_tempo' => ['nullable', 'date', 'after_or_equal:tanggal'],
            'keterangan' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $hasil = $this->service->terbitkan($data, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $pesan = $tagihan->ringkasanTerbit($hasil);
        if ($hasil['di_bawah_kuota'] !== []) {
            $n = count($hasil['di_bawah_kuota']);
            $pesan .= " {$n} santri masih di bawah kuota — setorannya tidak ditagih dan tetap terhitung untuk periode berikutnya.";
        }

        return redirect()->route('setoran_pemakaian.index', ['jenis' => $data['kode_jenis']])->with('status', $pesan);
    }
}
