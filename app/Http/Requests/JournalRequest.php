<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validasi bentuk Jurnal Umum. Balance (Σdebet = Σkredit), akun aktif, dan
 * bagian-wajib-untuk-beban divalidasi lebih dalam oleh PostingService/JournalService
 * (dilempar sebagai AppException) — di sini hanya bentuk dasar.
 */
class JournalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tanggal' => ['required', 'date'],
            'kode_unit' => ['nullable', 'string', 'exists:business_units,kode_unit'],
            'keterangan' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.kode_coa' => ['required', 'string', 'exists:coa_detail,kode_coa'],
            'lines.*.debet' => ['nullable', 'numeric', 'min:0'],
            'lines.*.kredit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.kode_bagian' => ['nullable', 'string', 'exists:bagian,kode_bagian'],
            'lines.*.keterangan' => ['nullable', 'string'],
            'lines.*.kode_persediaan' => ['nullable', 'string', 'exists:inventory,kode_persediaan'],
            'lines.*.kuantiti' => ['nullable', 'numeric', 'gt:0'],
        ];
    }

    /** Normalisasi baris untuk service: debet/kredit kosong → '0'; buang baris kosong. */
    public function lines(): array
    {
        $lines = [];
        foreach ($this->input('lines', []) as $l) {
            if (empty($l['kode_coa'])) {
                continue;
            }
            $debet = $l['debet'] ?? '';
            $kredit = $l['kredit'] ?? '';
            $lines[] = [
                'kode_coa' => $l['kode_coa'],
                'debet' => $debet === '' || $debet === null ? '0' : $debet,
                'kredit' => $kredit === '' || $kredit === null ? '0' : $kredit,
                'kode_bagian' => ($l['kode_bagian'] ?? '') ?: null,
                'keterangan' => $l['keterangan'] ?? null,
                // Mutasi stok per baris (opsional): debit=stok masuk, kredit=keluar.
                'kode_persediaan' => ($l['kode_persediaan'] ?? '') ?: null,
                'kuantiti' => ($l['kuantiti'] ?? '') !== '' ? $l['kuantiti'] : null,
            ];
        }

        return $lines;
    }
}
