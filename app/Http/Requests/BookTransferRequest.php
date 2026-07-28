<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validasi Pindah Buku (transfer antar rekening kas/bank). Jurnal: Debit
 * rekening tujuan; Kredit rekening asal. Rekening asal ≠ tujuan.
 */
class BookTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tanggal' => ['required', 'date'],
            'kode_rekening_asal' => ['required', 'string', 'exists:bank_accounts,kode_coa'],
            'kode_rekening_tujuan' => ['required', 'string', 'exists:bank_accounts,kode_coa', 'different:kode_rekening_asal'],
            // Unit OPSIONAL (samakan dev): bila kosong, jurnal memakai Default Unit modul.
            'kode_unit' => ['nullable', 'string', 'exists:business_units,kode_unit'],
            'nominal' => ['required', 'numeric', 'gt:0'],
            'keterangan' => ['required', 'string'],
        ];
    }

    public function attributes(): array
    {
        return [
            'kode_rekening_asal' => 'rekening asal',
            'kode_rekening_tujuan' => 'rekening tujuan',
        ];
    }

    public function messages(): array
    {
        return ['kode_rekening_tujuan.different' => 'Rekening tujuan harus berbeda dari rekening asal.'];
    }
}
