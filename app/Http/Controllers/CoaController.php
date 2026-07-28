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
        return view('coa.index', [
            'groups' => CoaGroup::orderBy('kode_grup')->get(),
            'details' => CoaDetail::orderBy('kode_coa')->get(),
        ]);
    }
}
