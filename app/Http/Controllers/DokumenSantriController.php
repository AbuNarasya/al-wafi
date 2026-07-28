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
            'kelengkapan' => $this->service->kelengkapan($id),
            'jenisOptions' => collect(self::JENIS)->mapWithKeys(fn ($j) => [$j => DokumenPolicy::LABEL_DOKUMEN[$j] ?? $j])->all(),
        ]);
    }

    public function store(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'jenis' => ['required', Rule::in(self::JENIS)],
            'keterangan' => ['nullable', 'string', 'max:255'],
            'berkas' => ['required', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
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
