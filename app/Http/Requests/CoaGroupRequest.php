<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CoaGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kode_grup' => $this->isMethod('post')
                ? ['required', 'string', 'max:255', Rule::unique('coa_groups', 'kode_grup')]
                : ['prohibited'],
            'nama_grup' => ['required', 'string', 'max:255'],
            'kode_induk' => ['nullable', 'string', Rule::exists('coa_groups', 'kode_grup')],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('kode_induk') === '') {
            $this->merge(['kode_induk' => null]);
        }
    }
}
