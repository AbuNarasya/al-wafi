<?php

namespace App\Http\Controllers;

use App\Models\BusinessUnit;
use App\Models\CompanySettings;
use App\Services\Ledger\PostingService;
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

        return view('company-settings.edit', [
            's' => $s,
            'opsiUnit' => BusinessUnit::orderBy('kode_unit')->pluck('nama_unit', 'kode_unit')->all(),
        ]);
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
            'kode_unit_neraca' => ['nullable', 'string', 'exists:business_units,kode_unit'],
            'keterangan' => ['nullable', 'string'],
        ]);
        $data['topup_tunai_dompet_santri'] = $request->boolean('topup_tunai_dompet_santri');
        $data['kode_unit_neraca'] = $data['kode_unit_neraca'] ?: null;

        CompanySettings::updateOrCreate(['id' => self::ID], $data);

        // Konteks neraca di-cache per proses; tanpa ini, jurnal yang diposting
        // sesudah pengaturannya diubah masih memakai unit penampung yang lama.
        PostingService::lupakanKonteksNeraca();

        return redirect()->route('company_settings.edit')->with('status', 'Pengaturan perusahaan berhasil disimpan.');
    }
}
