<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Services\Modules\PendaftaranLanjutanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Pendaftaran lanjutan (kenaikan jenjang internal) — dijalankan dari halaman
 * DETAIL SANTRI, satu santri per kali. Tidak ada halaman sendiri: tahapannya
 * mengikuti santri yang sedang dibuka, dan hasilnya pun tampil di sana.
 *
 * Semua aksinya POST & kembali ke halaman santri; tak ada satu pun yang bisa
 * terpicu oleh refresh halaman.
 */
class PendaftaranLanjutanController extends Controller
{
    public function __construct(private readonly PendaftaranLanjutanService $service) {}

    public function store(Request $request, int $idSantri): RedirectResponse
    {
        $data = $request->validate([
            'tahun_ajaran' => ['required', 'string', 'exists:tahun_ajaran,kode'],
            'catatan' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $p = $this->service->buat($idSantri, $data, (int) $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('santri.show', $idSantri)->with('status',
            "Pendaftaran lanjutan {$p->nomor} dibuka ke {$p->kode_jenjang} T.A {$p->tahun_ajaran}"
            .' (tahap "'.$p->labelStatus().'"). Status santri tetap aktif sampai kenaikannya dieksekusi.');
    }

    /**
     * Satu pintu untuk seluruh tahapan — sama seperti `SantriController::aksi()`,
     * supaya rantai tahapnya tetap dipegang satu tempat.
     */
    public function aksi(Request $request, int $idSantri, int $idPendaftaran, string $aksi): RedirectResponse
    {
        $idPengguna = (int) $request->user()->id_pengguna;

        try {
            match ($aksi) {
                'seleksi' => $this->service->majukan($idPendaftaran, 'diseleksi', $request->validate([
                    'nilai_baca' => ['nullable', 'numeric'],
                    'nilai_akademik' => ['nullable', 'numeric'],
                    'wawancara_wali' => ['nullable', 'string'],
                    'wawancara_santri' => ['nullable', 'string'],
                ]), $idPengguna),
                'pengumuman' => $this->service->majukan($idPendaftaran,
                    $request->boolean('lulus') ? 'diterima' : 'tidak_lulus',
                    ['catatan' => $request->input('catatan')], $idPengguna),
                'medcheck' => $this->service->majukan($idPendaftaran,
                    $request->boolean('lolos') ? 'lolos_kesehatan' : 'gagal_medcheck',
                    ['medcheck_ok' => $request->boolean('lolos'), 'catatan' => $request->input('catatan')], $idPengguna),
                'naik' => $this->service->eksekusiKenaikan($idPendaftaran, $request->validate([
                    'tingkat' => ['required', 'integer', 'min:1'],
                    'nominal_uang_pangkal' => ['nullable', 'numeric', 'gt:0'],
                    'nominal_perlengkapan' => ['nullable', 'numeric', 'min:0'],
                    'jatuh_tempo' => ['nullable', 'date'],
                ]), $idPengguna),
                'batal' => $this->service->batalkan($idPendaftaran,
                    (string) $request->validate(['alasan' => ['required', 'string', 'max:500']])['alasan'], $idPengguna),
                default => throw new AppException(404, "Aksi \"{$aksi}\" tidak dikenal."),
            };
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('santri.show', $idSantri)->with('status', 'Tahap pendaftaran lanjutan diperbarui.');
    }
}
