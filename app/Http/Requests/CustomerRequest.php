<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kode_customer' => $this->isMethod('post')
                ? ['required', 'string', 'max:255', Rule::unique('customers', 'kode_customer')]
                : ['prohibited'],
            'nama_customer' => ['required', 'string', 'max:255'],
            'kode_jenis_customer' => ['required', 'string', Rule::exists('customer_types', 'kode_jenis_customer')],
            'kode_coa_pendapatan' => ['nullable', 'string', Rule::exists('coa_detail', 'kode_coa')],
            'kode_coa_piutang' => ['nullable', 'string', Rule::exists('coa_detail', 'kode_coa')],
            'alamat' => ['nullable', 'string'],
            'telepon' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'status' => ['required', Rule::in(['aktif', 'nonaktif'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['kode_coa_pendapatan', 'kode_coa_piutang'] as $f) {
            if ($this->input($f) === '') {
                $this->merge([$f => null]);
            }
        }
    }

    public function tersimpan(): array
    {
        $fields = ['nama_customer', 'kode_jenis_customer', 'kode_coa_pendapatan', 'kode_coa_piutang', 'alamat', 'telepon', 'email', 'status'];
        $data = $this->safe()->only($fields);
        if ($this->isMethod('post')) {
            $data['kode_customer'] = $this->input('kode_customer');
        }

        return $data;
    }
}
