<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\DokumenSantri;
use App\Models\Santri;
use App\Services\Modules\DokumenSantriService;
use App\Services\Ppsb\DokumenPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Berkas Santri (KTP, akta, KK, med check, dsb.). Diakses dari detail santri.
 * Controller tipis → DokumenSantriService. Berkas disimpan di disk lokal.
 */
class DokumenSantriController extends Controller
{
    private const JENIS = ['ktp_ayah', 'ktp_ibu', 'ktp_wali', 'akta_kelahiran', 'kartu_keluarga', 'foto', 'surat_keterangan_aktif', 'surat_keterangan_kelakuan_baik', 'rapot_terakhir', 'surat_keterangan_sekolah', 'hasil_medcheck', 'lainnya'];

    public function __construct(private readonly DokumenSantriService $service) {}

    public function index(int $id): View
    {
        $santri = Santri::findOrFail($id);

        return view('dokumen-santri.index', [
            'santri' => $santri,
            'dokumen' => $this->service->list($id),
            'statusBerkas' => $this->service->statusBerkas($santri),
            'jenisOptions' => collect(self::JENIS)->mapWithKeys(fn ($j) => [$j => DokumenPolicy::LABEL_DOKUMEN[$j] ?? $j])->all(),
        ]);
    }

    public function store(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'jenis' => ['required', Rule::in(self::JENIS)],
            'keterangan' => ['nullable', 'string', 'max:255'],
            // Batas & daftar tipe dari satu tempat — lihat App\Support\Unggahan.
            'berkas' => \App\Support\Unggahan::aturan(),
        ]);
        $data['id_santri'] = $id;

        try {
            $this->service->unggah($data, $request->file('berkas'), $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('dokumen_santri.index', $id)->with('status', 'Berkas berhasil diunggah.');
    }

    /**
     * Staf memutus satu jenis berkas: "diterima" (boleh tanpa unggahan — diserahkan fisik),
     * "perlu_diganti" (wajib alasan; wali dikabari di portal), atau "batal" (kembali belum diputus).
     */
    public function putuskan(Request $request, int $id, string $jenis): RedirectResponse
    {
        $santri = Santri::findOrFail($id);
        abort_unless(isset(DokumenPolicy::TAHAP_DOKUMEN[$jenis]), 404);
        $data = $request->validate([
            'status' => ['required', Rule::in(['diterima', 'perlu_diganti', 'batal'])],
            'catatan' => ['nullable', 'string', 'max:500'],
        ]);
        $label = DokumenPolicy::LABEL_DOKUMEN[$jenis];

        if ($data['status'] === 'batal') {
            $this->service->batalkanPutusan($id, $jenis);

            return back()->with('status', "Keputusan atas {$label} dibatalkan.");
        }
        try {
            $this->service->putuskan($id, $jenis, $data['status'], $data['catatan'] ?? null, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($data['status'] === 'perlu_diganti') {
            $this->kabariWali($santri, "{$label} perlu diganti", "{$label} untuk {$santri->nama} perlu diunggah ulang: {$data['catatan']}");
        }

        return back()->with('status', $data['status'] === 'diterima' ? "{$label} ditandai diterima." : "{$label} ditandai perlu diganti — wali dikabari.");
    }

    /** Kabar ke setiap orang di keluarga yang bisa masuk portal (punya email). */
    private function kabariWali(Santri $santri, string $judul, string $pesan): void
    {
        $wali = $santri->wali;
        if (! $wali) {
            return;
        }
        foreach (array_keys(\App\Models\Wali::PERAN) as $p) {
            if (filled($wali->{"email_{$p}"})) {
                \App\Models\NotifikasiWali::create([
                    'id_wali' => $wali->id, 'peran' => $p, 'id_santri' => $santri->id,
                    'judul' => $judul, 'pesan' => $pesan, 'jenis' => 'berkas_perlu_diganti',
                    'ref_jenis' => 'BerkasSantri', 'ref_id' => (string) $santri->id,
                ]);
            }
        }
    }

    /**
     * Kontak wali kelas sekolah asal — data santri (bukan berkas), disunting dari
     * halaman berkas karena dilengkapi bersama berkas pindahan.
     */
    public function waliKelas(Request $request, int $id): RedirectResponse
    {
        $santri = Santri::findOrFail($id);
        $data = $request->validate([
            'wali_kelas_asal' => ['nullable', 'string', 'max:255'],
            'cp_wali_kelas_asal' => ['nullable', 'string', 'max:255'],
        ]);
        $santri->update($data);

        return redirect()->route('dokumen_santri.index', $id)->with('status', 'Data wali kelas sekolah asal disimpan.');
    }

    public function destroy(int $dokumen): RedirectResponse
    {
        $idSantri = DokumenSantri::where('id', $dokumen)->value('id_santri');

        try {
            $this->service->hapus($dokumen);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('dokumen_santri.index', $idSantri)->with('status', 'Berkas dihapus.');
    }

    public function download(int $dokumen): StreamedResponse
    {
        $row = DokumenSantri::findOrFail($dokumen);
        abort_unless(Storage::disk('local')->exists($row->path), 404);

        return Storage::disk('local')->download($row->path, $row->nama_asli);
    }

    /**
     * Sajikan berkas INLINE (Content-Disposition: inline) untuk pratinjau di
     * dalam app — dipakai PdfViewer (PDF.js) & <img>. Beda dari download() yang
     * memaksa unduh.
     */
    public function berkas(int $dokumen): StreamedResponse
    {
        $row = DokumenSantri::findOrFail($dokumen);
        abort_unless(Storage::disk('local')->exists($row->path), 404);

        return Storage::disk('local')->response($row->path, $row->nama_asli, [
            'Content-Type' => $row->mime,
            'Content-Disposition' => 'inline; filename="'.addslashes($row->nama_asli).'"',
        ]);
    }
}
