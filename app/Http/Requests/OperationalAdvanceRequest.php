<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validasi Uang Muka Operasional. Jurnal: Debit akun uang muka; Kredit kas/bank.
 * Penyelesaian dilakukan lewat modul Penyelesaian Uang Muka.
 */
class OperationalAdvanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tanggal' => ['required', 'date'],
            'kode_coa_uang_muka' => ['required', 'string', 'exists:coa_detail,kode_coa'],
            'kode_rekening' => ['required', 'string', 'exists:bank_accounts,kode_coa'],
            'kode_unit' => ['nullable', 'string', 'exists:business_units,kode_unit'],
            'kode_bagian' => ['nullable', 'string', 'exists:bagian,kode_bagian'],
            'penerima' => ['nullable', 'string', 'max:255'],
            'nominal' => ['required', 'numeric', 'gt:0'],
            'keterangan' => ['required', 'string'],
        ];
    }

    public function attributes(): array
    {
        return ['kode_coa_uang_muka' => 'akun uang muka', 'kode_rekening' => 'kas/rekening sumber'];
    }
}
