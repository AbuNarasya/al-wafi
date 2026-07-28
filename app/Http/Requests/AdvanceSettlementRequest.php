<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validasi Penyelesaian Uang Muka. Jurnal: Kredit akun uang muka, Debit akun
 * realisasi; selisih (realisasi vs uang muka) lewat Kas/Bank.
 */
class AdvanceSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tanggal' => ['required', 'date'],
            'id_uang_muka' => ['required', 'integer', 'exists:operational_advances,id'],
            'nominal_uang_muka' => ['required', 'numeric', 'gt:0'],
            'kode_coa_realisasi' => ['required', 'string', 'exists:coa_detail,kode_coa'],
            'nominal_realisasi' => ['required', 'numeric', 'gt:0'],
            'kode_rekening' => ['required', 'string', 'exists:bank_accounts,kode_coa'],
            'kode_unit' => ['nullable', 'string', 'exists:business_units,kode_unit'],
            'kode_bagian' => ['nullable', 'string', 'exists:bagian,kode_bagian'],
            'keterangan' => ['required', 'string'],
        ];
    }

    public function attributes(): array
    {
        return [
            'id_uang_muka' => 'uang muka',
            'nominal_uang_muka' => 'nominal uang muka diselesaikan',
            'kode_coa_realisasi' => 'akun realisasi',
            'nominal_realisasi' => 'nominal realisasi',
            'kode_rekening' => 'kas/rekening selisih',
        ];
    }
}
