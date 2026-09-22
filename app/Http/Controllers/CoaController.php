<?php

namespace App\Http\Controllers;

use App\Models\CoaDetail;
use App\Models\CoaGroup;
use Illuminate\View\View;

/**
 * Chart of Account terpadu (port CoaPage.tsx dev): satu halaman /coa dengan
 * tab Struktur Pohon + Grup COA + Detail COA. CRUD tetap lewat modul
 * coa-groups / coa-detail (controller terpisah).
 */
class CoaController extends Controller
{
    public function index(): View
    {
        $details = CoaDetail::orderBy('kode_coa')->get();

        // Dihitung di sini, bukan di Blade: akar kelompok diambil dari peta satu
        // kueri. Versi pertama memanggil CoaDetail::akarKelompok() per akun dari
        // dalam view, dan seratus akun berarti ratusan kueri — tak terasa di
        // laptop, tetapi dari Hostinger ke Neon Singapura halamannya kehabisan waktu.
        $akar = CoaDetail::petaAkarKelompok();

        return view('coa.index', [
            'groups' => CoaGroup::orderBy('kode_grup')->get(),
            'details' => $details,
            'belumArusKas' => $details->filter(fn ($a) => $a->klasifikasi_arus_kas === null
                && in_array($akar[$a->kode_grup] ?? null, ['1', '2', '3'], true))->count(),
        ]);
    }
}
