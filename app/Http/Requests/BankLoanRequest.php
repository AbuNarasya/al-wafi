<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi Pembiayaan Bank (syariah). Opsi posting_pencairan → jurnal Debit
 * Kas/Bank, Kredit akun hutang pembiayaan sebesar pokok.
 */
class BankLoanRequest extends FormRequest
{
    public const AKAD = ['murabahah', 'ijarah', 'musyarakah_mutanaqishah', 'qardh', 'istishna', 'lainnya'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nama_bank' => ['required', 'string', 'max:255'],
            'nomor_kontrak' => ['nullable', 'string', 'max:255'],
            'jenis_akad' => ['required', Rule::in(self::AKAD)],
            'pokok_awal' => ['required', 'numeric', 'gt:0'],
            'margin' => ['nullable', 'numeric', 'min:0'],
            'tenor_bulan' => ['nullable', 'integer', 'min:1'],
            'tanggal_mulai' => ['required', 'date'],
            'tanggal_jatuh_tempo' => ['nullable', 'date', 'after_or_equal:tanggal_mulai'],
            'kode_coa_hutang' => ['required', 'string', 'exists:coa_detail,kode_coa'],
            'kode_coa_beban_bunga' => ['nullable', 'string', 'exists:coa_detail,kode_coa'],
            'kode_rekening' => ['required', 'string', 'exists:bank_accounts,kode_coa'],
            'keterangan' => ['nullable', 'string'],
            'posting_pencairan' => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'kode_coa_hutang' => 'akun hutang pembiayaan',
            'kode_coa_beban_bunga' => 'akun beban margin',
            'kode_rekening' => 'rekening pencairan',
        ];
    }
}
