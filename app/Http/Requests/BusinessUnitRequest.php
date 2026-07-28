<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BusinessUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isCreate = $this->isMethod('post');

        return [
            'kode_unit' => $isCreate
                ? ['required', 'string', 'max:255', Rule::unique('business_units', 'kode_unit')]
                : ['prohibited'],
            'nama_unit' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(['aktif', 'nonaktif'])],
        ];
    }

    public function attributes(): array
    {
        return ['kode_unit' => 'kode unit', 'nama_unit' => 'nama unit'];
    }

    public function tersimpan(): array
    {
        $data = $this->safe()->only(['nama_unit', 'status']);
        if ($this->isMethod('post')) {
            $data['kode_unit'] = $this->input('kode_unit');
        }

        return $data;
    }
}
