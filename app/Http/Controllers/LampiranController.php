<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\LampiranDokumen;
use App\Services\Modules\LampiranService;
use App\Support\Akses;
use App\Support\SumberLampiran;
use App\Support\Unggahan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * LAMPIRAN DOKUMEN — satu layar untuk semua sumber yang boleh dilampiri
 * (pengajuan pembayaran, uang muka operasional, penyelesaian uang muka).
 *
 * Hak aksesnya TIDAK bisa dipasang lewat middleware `hakakses:` seperti rute
 * lain: modul yang menggerbangi bergantung pada `{jenis}` di URL-nya. Karena itu
 * pemeriksaannya di sini, lewat Akses::boleh() dengan modul yang disebut
 * [[SumberLampiran]] — hak `lihat` untuk membuka & mengunduh, `buat` untuk
 * melampirkan, `hapus` untuk membuang. Tak ada modul hak akses baru: yang sudah
 * boleh membuat pengajuan otomatis boleh melampirkan berkasnya.
 */
class LampiranController extends Controller
{
    public function __construct(private readonly LampiranService $service) {}

    /** Daftar lampiran sebuah dokumen + form unggah. */
    public function index(string $jenis, string $id): View
    {
        $dokumen = $this->dokumen($jenis, $id, 'lihat');

        return view('lampiran.index', [
            'jenis' => $jenis,
            'idDokumen' => $id,
            'label' => SumberLampiran::label($jenis),
            'nomor' => SumberLampiran::nomor($jenis, $dokumen),
            'status' => $dokumen->status,
            'terbuka' => SumberLampiran::terbuka($jenis, $dokumen),
            'rows' => $this->service->daftar($jenis, $id),
            'urlKembali' => SumberLampiran::urlKembali($jenis, $id),
            'bolehUnggah' => Akses::boleh(SumberLampiran::modul($jenis), 'buat'),
            'bolehHapus' => Akses::boleh(SumberLampiran::modul($jenis), 'hapus'),
            'maksLabel' => Unggahan::maksLabel(),
        ]);
    }

    public function store(Request $request, string $jenis, string $id): RedirectResponse
    {
        $this->dokumen($jenis, $id, 'buat');

        $request->validate([
            'berkas' => Unggahan::aturan(),
            'keterangan' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->service->unggah(
                $jenis, $id, $request->file('berkas'),
                $request->user()->id_pengguna, $request->input('keterangan'),
            );
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('lampiran.index', [$jenis, $id])->with('status', 'Lampiran berhasil diunggah.');
    }

    public function destroy(int $lampiran): RedirectResponse
    {
        $row = LampiranDokumen::findOrFail($lampiran);
        $this->pastikanBoleh($row->jenis_dokumen, 'hapus');

        try {
            $this->service->hapus($lampiran);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('lampiran.index', [$row->jenis_dokumen, $row->id_dokumen])
            ->with('status', 'Lampiran dihapus.');
    }

    /** Paksa unduh. */
    public function unduh(int $lampiran): StreamedResponse
    {
        $row = $this->berkasTerbaca($lampiran);

        return \Illuminate\Support\Facades\Storage::disk($row->disk ?: 'local')
            ->download($row->path, $row->nama_asli);
    }

    /**
     * Sajikan INLINE untuk pratinjau di dalam aplikasi (PDF.js & <img>).
     * Beda dari unduh() yang memaksa berkasnya turun ke komputer.
     */
    public function berkas(int $lampiran): StreamedResponse
    {
        $row = $this->berkasTerbaca($lampiran);

        return \Illuminate\Support\Facades\Storage::disk($row->disk ?: 'local')->response(
            $row->path, $row->nama_asli, [
                'Content-Type' => $row->mime,
                'Content-Disposition' => 'inline; filename="'.addslashes($row->nama_asli).'"',
            ],
        );
    }

    /** Lampiran yang berkasnya benar-benar masih ada, sesudah hak aksesnya lolos. */
    private function berkasTerbaca(int $lampiran): LampiranDokumen
    {
        $row = LampiranDokumen::findOrFail($lampiran);
        $this->pastikanBoleh($row->jenis_dokumen, 'lihat');

        // Berkas bisa hilang tanpa barisnya ikut hilang — di server tanpa disk
        // permanen (Render paket gratis) itu terjadi tiap kali server restart.
        abort_unless(
            \Illuminate\Support\Facades\Storage::disk($row->disk ?: 'local')->exists($row->path),
            404,
            'Berkas lampiran ini sudah tidak ada di penyimpanan.',
        );

        return $row;
    }

    /** Dokumen induk yang ada & boleh disentuh pembacanya. */
    private function dokumen(string $jenis, string $id, string $aksi): \Illuminate\Database\Eloquent\Model
    {
        $this->pastikanBoleh($jenis, $aksi);

        $dokumen = SumberLampiran::dokumen($jenis, $id);
        abort_unless($dokumen !== null, 404, SumberLampiran::label($jenis).' tidak ditemukan.');

        return $dokumen;
    }

    private function pastikanBoleh(string $jenis, string $aksi): void
    {
        abort_unless(SumberLampiran::dikenal($jenis), 404, 'Jenis dokumen tidak dikenal.');
        abort_unless(Akses::boleh(SumberLampiran::modul($jenis), $aksi), 403);
    }
}
