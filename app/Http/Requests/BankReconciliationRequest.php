<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validasi pembuatan Rekonsiliasi Bank (draft). saldo_bank = saldo per rekening
 * koran; saldo_buku dihitung service dari GL.
 */
class BankReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kode_coa' => ['required', 'string', 'exists:bank_accounts,kode_coa'],
            'tanggal' => ['required', 'date'],
            'saldo_bank' => ['required', 'numeric'],
            'keterangan' => ['nullable', 'string'],
        ];
    }

    public function attributes(): array
    {
        return ['kode_coa' => 'akun bank', 'saldo_bank' => 'saldo rekening koran'];
    }
}
