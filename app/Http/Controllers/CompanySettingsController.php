<?php

namespace App\Http\Controllers;

use App\Models\CompanySettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Pengaturan perusahaan — baris tunggal (singleton id=1). Hanya edit.
 * periode_awal_pembukuan menggerakkan Laba/Rugi Tahun Berjalan di Neraca.
 */
class CompanySettingsController extends Controller
{
    private const ID = 1;

    public function edit(): View
    {
        $s = CompanySettings::find(self::ID) ?? new CompanySettings(['id' => self::ID, 'mata_uang' => 'IDR', 'bulan_awal_anggaran' => 1]);

        return view('company-settings.edit', ['s' => $s]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'nama_perusahaan' => ['required', 'string', 'max:255'],
            'jenis_perusahaan' => ['required', 'string', 'max:255'],
            'alamat' => ['nullable', 'string'],
            'telepon' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'npwp' => ['nullable', 'string', 'max:255'],
            'mata_uang' => ['required', 'string', 'max:10'],
            'periode_awal_pembukuan' => ['required', 'date'],
            'bulan_awal_anggaran' => ['required', 'integer', 'between:1,12'],
            'topup_tunai_dompet_santri' => ['nullable', 'boolean'],
            'keterangan' => ['nullable', 'string'],
        ]);
        $data['topup_tunai_dompet_santri'] = $request->boolean('topup_tunai_dompet_santri');

        CompanySettings::updateOrCreate(['id' => self::ID], $data);

        return redirect()->route('company_settings.edit')->with('status', 'Pengaturan perusahaan berhasil disimpan.');
    }
}
