<?php

namespace App\Http\Controllers;

use App\Models\BusinessUnit;
use App\Models\CoaDetail;
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
            // Akun liabilitas saja (kelompok 2) — penampung barang diterima belum ditagih.
            // Biaya VA Midtrans (24 Okt 2026) — hanya instans yang punya portal wali.
            'biayaVa' => \App\Support\Instans::portalWali() ? [
                'berlaku' => \App\Services\Midtrans\TarifVa::berlaku(),
                'terjadwal' => \App\Services\Midtrans\TarifVa::terjadwal(),
                'riwayat' => \App\Services\Midtrans\TarifVa::riwayat(),
            ] : null,
            'opsiGrni' => CoaDetail::where('status', 'aktif')->where('kode_coa', 'like', '2%')->orderBy('kode_coa')->get()
                ->mapWithKeys(fn ($c) => [$c->kode_coa => "{$c->kode_coa} — {$c->nama_coa}"])->all(),
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
            'kode_coa_grni' => ['nullable', 'string', 'exists:coa_detail,kode_coa'],
            'batas_jajan_harian' => ['nullable', 'numeric', 'min:0'],
            'kode_unit_wafimart' => ['nullable', 'string', 'exists:business_units,kode_unit'],
            'keterangan' => ['nullable', 'string'],
        ]);
        // Tak terkirim (formulir lama) = biarkan; terkirim kosong = dikosongkan.
        if ($request->has('kode_unit_wafimart')) {
            $data['kode_unit_wafimart'] = ($data['kode_unit_wafimart'] ?? null) ?: null;
        }
        $data['kode_coa_grni'] = ($data['kode_coa_grni'] ?? null) ?: null;
        // Tak terkirim (formulir lama) = biarkan nilai tersimpan; jangan diam-diam jadi nol.
        if (($data['batas_jajan_harian'] ?? null) === null) {
            unset($data['batas_jajan_harian']);
        }
        // Isiannya tak tampil di instans Wafi Mart — tak terkirim = biarkan nilai tersimpan.
        if ($request->has('topup_tunai_dompet_santri')) {
            $data['topup_tunai_dompet_santri'] = $request->boolean('topup_tunai_dompet_santri');
        }
        $data['kode_unit_neraca'] = ($data['kode_unit_neraca'] ?? null) ?: null;

        CompanySettings::updateOrCreate(['id' => self::ID], $data);

        // Konteks neraca di-cache per proses; tanpa ini, jurnal yang diposting
        // sesudah pengaturannya diubah masih memakai unit penampung yang lama.
        PostingService::lupakanKonteksNeraca();

        return redirect()->route('company_settings.edit')->with('status', 'Pengaturan perusahaan berhasil disimpan.');
    }

    /** Tarif biaya Virtual Account Midtrans — lihat App\Services\Midtrans\TarifVa. */
    public function simpanBiayaVa(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'biaya_va' => ['required', 'numeric', 'min:0', 'max:100000'],
            'berlaku_mulai' => ['required', 'date', 'after_or_equal:today'],
            'catatan_va' => ['nullable', 'string', 'max:500'],
        ], ['berlaku_mulai.after_or_equal' => 'Tanggal mulai berlaku tidak boleh lampau.']);
        try {
            \App\Services\Midtrans\TarifVa::simpan($d['biaya_va'], $d['berlaku_mulai'], $d['catatan_va'] ?? null, $request->user()->id_pengguna);
        } catch (\App\Exceptions\AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('company_settings.edit')->with('status', \Illuminate\Support\Carbon::parse($d['berlaku_mulai'])->isToday()
            ? 'Biaya VA baru berlaku mulai sekarang untuk VA yang terbit sesudah ini.'
            : 'Biaya VA baru dijadwalkan.');
    }

    public function batalBiayaVa(Request $request, int $id): RedirectResponse
    {
        try {
            \App\Services\Midtrans\TarifVa::batalkan($id, $request->user()->id_pengguna);
        } catch (\App\Exceptions\AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Tarif terjadwal dibatalkan.');
    }
}
