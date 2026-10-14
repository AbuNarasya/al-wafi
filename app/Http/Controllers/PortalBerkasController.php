<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\DokumenSantri;
use App\Models\HakAksesModul;
use App\Models\Santri;
use App\Models\User;
use App\Services\Modules\DokumenSantriService;
use App\Services\Modules\NotificationService;
use App\Services\PortalWali\SesiWali;
use App\Services\Ppsb\DokumenPolicy;
use App\Services\Ppsb\Tahap;
use App\Support\Unggahan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * PORTAL WALI — berkas PSB anak (keputusan user 15 Okt 2026).
 *
 * Berkas registrasi = DAFTAR PERIKSA yang diingatkan, bukan penghalang tahap —
 * sama dengan proses manualnya. Berkas tersimpan di tempat yang SAMA dengan
 * unggahan staf (DokumenSantriService), jadi staf memeriksanya di halaman Dokumen
 * seperti biasa; staf boleh menandai "diterima" walau berkasnya diserahkan fisik.
 *
 * Setiap kueri berangkat dari keluarga yang sedang masuk (selain itu 404).
 */
class PortalBerkasController extends Controller
{
    public function __construct(private readonly DokumenSantriService $dokumen) {}

    private function anak(int $id): Santri
    {
        return Santri::where('id_wali', SesiWali::wali()->id)->findOrFail($id);
    }

    public function index(int $id): View
    {
        $santri = $this->anak($id);

        return view('portal.berkas', [
            'santri' => $santri, 'daftar' => $this->dokumen->statusBerkas($santri),
            'bolehUnggah' => ! Tahap::isFinal((string) $santri->status),
        ]);
    }

    public function unggah(Request $request, int $id): RedirectResponse
    {
        $santri = $this->anak($id);
        $data = $request->validate([
            'jenis' => ['required', Rule::in(array_keys(DokumenPolicy::TAHAP_DOKUMEN))],
            'keterangan' => ['nullable', 'string', 'max:255'],
            'berkas' => Unggahan::aturan(),
        ], [
            'berkas.required' => 'Pilih foto atau PDF berkasnya.',
            'berkas.max' => 'Berkas terlalu besar (maksimal '.Unggahan::maksLabel().').',
            'berkas.mimes' => 'Berkas harus foto (JPG/PNG/WEBP) atau PDF.',
        ]);
        $data['id_santri'] = $santri->id;
        $peran = (string) SesiWali::peran($request);

        try {
            $this->dokumen->unggah($data, $request->file('berkas'), null, ['id_wali' => SesiWali::wali()->id, 'peran' => $peran]);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }
        $label = DokumenPolicy::LABEL_DOKUMEN[$data['jenis']];
        $this->kabariStaf($santri, $label, $peran);

        return redirect()->route('portal.berkas', $santri->id)->with('status', "{$label} terkirim. Pesantren akan memeriksanya.");
    }

    public function hapus(int $dokumen): RedirectResponse
    {
        $idSantri = DokumenSantri::where('id', $dokumen)->value('id_santri');
        try {
            $this->dokumen->hapusOlehWali($dokumen, SesiWali::wali()->id);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('portal.berkas', $idSantri)->with('status', 'Berkas dihapus.');
    }

    /** Pratinjau berkas — hanya milik anak keluarga yang sedang masuk. */
    public function lihat(int $dokumen): StreamedResponse
    {
        $row = DokumenSantri::whereHas('santri', fn ($q) => $q->where('id_wali', SesiWali::wali()->id))->findOrFail($dokumen);
        abort_unless(Storage::disk('local')->exists($row->path), 404);

        return Storage::disk('local')->response($row->path, $row->nama_asli, [
            'Content-Type' => $row->mime,
            'Content-Disposition' => 'inline; filename="'.addslashes($row->nama_asli).'"',
        ]);
    }

    /** Pemegang hak buat Berkas Santri (+ admin) diberi kabar — bukan tugas, cukup kabar. */
    private function kabariStaf(Santri $santri, string $label, string $peran): void
    {
        try {
            $ids = HakAksesModul::where('kode_modul', 'dokumen-santri')->where('buat', true)
                ->whereHas('pengguna', fn ($q) => $q->where('status', 'aktif'))->pluck('id_pengguna')
                ->merge(User::where('is_admin', true)->where('status', 'aktif')->pluck('id_pengguna'))
                ->map(fn ($v) => (int) $v)->unique()->values();
            (new NotificationService)->kirim($ids->map(fn (int $id) => [
                'id_pengguna' => $id, 'judul' => 'Berkas PSB diunggah wali',
                'pesan' => "{$label} untuk {$santri->nama} diunggah ".strtolower(\App\Models\Wali::PERAN[$peran] ?? 'wali').' lewat portal — menunggu diperiksa.',
                'jenis' => 'berkas_wali_diunggah', 'ref_jenis' => 'BerkasSantri', 'ref_id' => (string) $santri->id,
            ])->all());
        } catch (\Throwable) {
            // kabar tak boleh menggagalkan unggahan yang sudah tersimpan
        }
    }
}
