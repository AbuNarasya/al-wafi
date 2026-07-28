<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validasi Accrue & Prepaid (jurnal penyesuaian). Jurnal: Debit akun debet,
 * Kredit akun kredit. Reversal awal bulan membalik accrue periode sebelumnya.
 */
class AccrueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tanggal' => ['required', 'date'],
            'periode' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'kode_coa_debet' => ['required', 'string', 'exists:coa_detail,kode_coa'],
            'kode_coa_kredit' => ['required', 'string', 'exists:coa_detail,kode_coa', 'different:kode_coa_debet'],
            'nominal' => ['required', 'numeric', 'gt:0'],
            'kode_unit' => ['nullable', 'string', 'exists:business_units,kode_unit'],
            'kode_bagian' => ['nullable', 'string', 'exists:bagian,kode_bagian'],
            'keterangan' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'kode_coa_kredit.different' => 'Akun kredit harus berbeda dari akun debet.',
            'periode.regex' => 'Periode harus format YYYY-MM (mis. 2026-07).',
        ];
    }

    public function attributes(): array
    {
        return ['kode_coa_debet' => 'akun debet', 'kode_coa_kredit' => 'akun kredit'];
    }
}
