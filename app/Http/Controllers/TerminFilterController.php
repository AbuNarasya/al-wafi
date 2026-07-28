<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Services\Modules\TerminFilterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Setting Filter Termin Jatuh Tempo (PPSB → Master) — singleton, edit-only
 * (pola company-settings). Mengatur pilihan & default dropdown "Termin jatuh
 * tempo — perlu ditagih" di halaman Angsuran Uang Pangkal.
 */
class TerminFilterController extends Controller
{
    public function __construct(private readonly TerminFilterService $service) {}

    public function edit(): View
    {
        $s = $this->service->pengaturan();

        return view('termin-filter.edit', [
            's' => $s,
            'opsi' => $this->service->opsi($s),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'pilihan_hari' => ['required', 'string', 'max:100', 'regex:/^\s*\d{1,3}\s*(,\s*\d{1,3}\s*)*$/'],
            'default_hari' => ['required', 'integer', 'between:0,365'],
        ], [
            'pilihan_hari.regex' => 'Isi daftar hari dipisah koma, contoh: 7,14,30 (1–365 hari).',
        ]);

        try {
            $this->service->simpan($data);
        } catch (AppException $e) {
            return back()->withInput()->withErrors(['default_hari' => $e->getMessage()]);
        }

        return redirect()->route('termin_filter.edit')->with('status', 'Setting filter termin berhasil disimpan.');
    }
}
