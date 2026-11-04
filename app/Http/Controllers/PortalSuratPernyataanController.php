<?php

namespace App\Http\Controllers;

use App\Models\PersetujuanTerm;
use App\Models\TermTemplate;
use App\Services\PortalWali\SesiWali;
use App\Support\Unggahan;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Unduh PDF surat pernyataan dari portal wali (4 Nov 2026).
 *
 * Boleh diunduh: versi yang sedang AKTIF (dibaca sebelum mendaftar), atau versi
 * yang pernah DISETUJUI keluarga ini (dilihat lagi di halaman anak). Versi lain → 404,
 * supaya draf yang belum diaktifkan petugas tak bocor.
 */
class PortalSuratPernyataanController extends Controller
{
    public function pdf(int $id): StreamedResponse
    {
        $surat = TermTemplate::find($id);
        abort_unless($surat && $surat->berkas_pdf, 404);

        $wali = SesiWali::wali();
        $boleh = $surat->status === 'aktif'
            || ($wali && PersetujuanTerm::where('id_term_template', $surat->id)->where('id_wali', $wali->id)->exists());
        abort_unless($boleh, 404);

        return Unggahan::sajikan('local', $surat->berkas_pdf, $surat->berkas_pdf_nama);
    }
}
