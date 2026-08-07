<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Services\Modules\KepesertaanLainService;
use App\Services\Modules\TagihanLainService;
use App\Support\Referensi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * KELUARGA B — matriks tarif per jenjang & daftar peserta kegiatan.
 *
 * Dipisah dari TagihanLainController yang menerbitkan: yang di sini disusun
 * sekali lalu dipakai berkali-kali, yang di sana dijalankan tiap kegiatan.
 * Controller tipis → KepesertaanLainService.
 */
class KepesertaanLainController extends Controller
{
    public function __construct(
        private readonly KepesertaanLainService $service = new KepesertaanLainService,
    ) {}

    // ---- Matriks tarif ----

    public function tarif(): View
    {
        return view('tagihan-lain.tarif', ['grid' => $this->service->grid()]);
    }

    public function simpanTarif(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sel' => ['array'],
            'sel.*' => ['array'],
            'sel.*.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        $hasil = $this->service->simpanGrid($data['sel'] ?? []);

        $pesan = "Matriks tarif tersimpan — {$hasil['tersimpan']} sel berlaku";
        $pesan .= $hasil['dihapus'] > 0 ? ", {$hasil['dihapus']} sel dikosongkan." : '.';
        if ($hasil['peserta_menggantung'] !== []) {
            // Bukan digagalkan: petugas boleh saja sengaja mencabut sebuah
            // jenjang. Yang tak boleh adalah ia tidak diberi tahu akibatnya.
            $pesan .= ' Perhatikan — peserta ini kini tanpa tarif dan tak akan ditagih: '
                .implode(', ', array_slice($hasil['peserta_menggantung'], 0, 8)).'.';
        }

        return redirect()->route('tagihan_lain.tarif')->with('status', $pesan);
    }

    // ---- Daftar peserta ----

    public function peserta(Request $request): View
    {
        $daftarJenis = $this->service->jenisKepesertaan();
        $kode = trim((string) $request->query('jenis', '')) ?: (string) $daftarJenis->first()?->kode;

        return view('tagihan-lain.peserta', [
            'opsiJenis' => $daftarJenis->mapWithKeys(fn ($j) => [$j->kode => $j->nama])->all(),
            'kodeJenis' => $kode,
            'jenis' => $kode !== '' ? $daftarJenis->firstWhere('kode', $kode) : null,
            'baris' => $kode !== '' ? $this->service->peserta($kode) : [],
            'santriAktif' => Referensi::santri('aktif'),
        ]);
    }

    public function tambahPeserta(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kode_jenis' => ['required', 'string'],
            'id_santri' => ['required', 'integer'],
            'nominal' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $p = $this->service->tambah($data['kode_jenis'], (int) $data['id_santri'], $data['nominal'] ?? null);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('tagihan_lain.peserta', ['jenis' => $data['kode_jenis']])
            ->with('status', "{$p->santri?->nama} ditambahkan sebagai peserta.");
    }

    public function ubahPeserta(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['nominal' => ['nullable', 'numeric', 'min:0']]);

        try {
            $p = $this->service->ubahNominal($id, $data['nominal'] ?? null);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('tagihan_lain.peserta', ['jenis' => $p->kode_jenis])->with(
            'status',
            $p->nominal === null
                ? "{$p->santri?->nama} kembali mengikuti tarif jenjangnya."
                : "Nominal khusus {$p->santri?->nama} disimpan.",
        );
    }

    public function statusPeserta(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'string']]);

        try {
            $p = $this->service->ubahStatus($id, $data['status']);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('tagihan_lain.peserta', ['jenis' => $p->kode_jenis])->with(
            'status',
            $p->ikut() ? "{$p->santri?->nama} diikutkan lagi." : "{$p->santri?->nama} dihentikan dari kegiatan ini.",
        );
    }

    // ---- Penerbitan dari daftar peserta ----

    public function terbitkan(Request $request, TagihanLainService $tagihan): RedirectResponse
    {
        $data = $request->validate([
            'kode_jenis' => ['required', 'string'],
            'tanggal' => ['required', 'date'],
            'jatuh_tempo' => ['nullable', 'date', 'after_or_equal:tanggal'],
            'periode' => ['nullable', 'string', 'max:255'],
            'keterangan' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $hasil = $tagihan->terbitkanPeserta($data, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('tagihan_lain.index')->with('status', $tagihan->ringkasanTerbit($hasil));
    }
}
