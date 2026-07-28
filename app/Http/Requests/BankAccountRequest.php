<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kode_coa' => $this->isMethod('post')
                ? ['required', 'string', Rule::exists('coa_detail', 'kode_coa'), Rule::unique('bank_accounts', 'kode_coa')]
                : ['prohibited'],
            'nama_rekening' => ['required', 'string', 'max:255'],
            'jenis_rekening' => ['required', Rule::in(['tunai', 'bank'])],
            'nama_bank' => ['nullable', 'string', 'max:255'],
            'no_rekening' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(['aktif', 'nonaktif'])],
        ];
    }

    public function attributes(): array
    {
        return ['kode_coa' => 'akun COA kas/bank', 'nama_rekening' => 'nama rekening'];
    }

    public function tersimpan(): array
    {
        $data = $this->safe()->only(['nama_rekening', 'jenis_rekening', 'nama_bank', 'no_rekening', 'status']);
        if ($this->isMethod('post')) {
            $data['kode_coa'] = $this->input('kode_coa');
        }

        return $data;
    }
}
