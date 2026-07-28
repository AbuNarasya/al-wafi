<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CoaDetailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kode_coa' => $this->isMethod('post')
                ? ['required', 'string', 'max:255', 'regex:/^\S+$/', Rule::unique('coa_detail', 'kode_coa')]
                : ['prohibited'],
            'nama_coa' => ['required', 'string', 'max:255'],
            'kode_grup' => ['required', 'string', Rule::exists('coa_groups', 'kode_grup')],
            'jenis_saldo' => ['required', Rule::in(['debet', 'kredit'])],
            'status' => ['required', Rule::in(['aktif', 'nonaktif'])],
            'keterangan' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return ['kode_coa.regex' => 'Kode akun tidak boleh mengandung spasi.'];
    }

    public function attributes(): array
    {
        return ['kode_coa' => 'kode akun', 'nama_coa' => 'nama akun', 'kode_grup' => 'grup COA'];
    }

    public function tersimpan(): array
    {
        $data = $this->safe()->only(['nama_coa', 'kode_grup', 'jenis_saldo', 'status', 'keterangan']);
        if ($this->isMethod('post')) {
            $data['kode_coa'] = $this->input('kode_coa');
        }

        return $data;
    }
}
