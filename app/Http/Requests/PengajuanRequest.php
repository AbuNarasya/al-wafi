<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validasi bentuk Pengajuan Pembayaran (jenis "pembayaran"). Aturan berat
 * (staff-only, evaluasi anggaran, akun/unit aktif) ditegakkan
 * PengajuanPembayaranService (AppException).
 */
class PengajuanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tanggal' => ['required', 'date'],
            'keterangan' => ['required', 'string'],
            'referensi' => ['nullable', 'string', 'max:255'],
            'details' => ['required', 'array', 'min:1'],
            'details.*.kode_coa' => ['required', 'string', 'exists:coa_detail,kode_coa'],
            'details.*.kode_unit' => ['required', 'string', 'exists:business_units,kode_unit'],
            'details.*.nominal' => ['required', 'numeric', 'gt:0'],
            'details.*.keterangan' => ['nullable', 'string'],
        ];
    }

    /** Baris rincian untuk service. */
    public function details(): array
    {
        $out = [];
        foreach ($this->input('details', []) as $d) {
            if (empty($d['kode_coa'])) {
                continue;
            }
            $out[] = [
                'kode_coa' => $d['kode_coa'],
                'kode_unit' => $d['kode_unit'],
                'nominal' => $d['nominal'],
                'keterangan' => $d['keterangan'] ?? null,
            ];
        }

        return $out;
    }
}
