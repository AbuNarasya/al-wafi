<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi Bagian. `level` TIDAK divalidasi di sini — dihitung dari induk oleh
 * controller (root = 1, selain itu induk.level + 1, maksimal 3 tingkat).
 */
class BagianRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isCreate = $this->isMethod('post');

        return [
            'kode_bagian' => $isCreate
                ? ['required', 'string', 'max:255', Rule::unique('bagian', 'kode_bagian')]
                : ['prohibited'],
            'nama_bagian' => ['required', 'string', 'max:255'],
            'kode_induk' => ['nullable', 'string', Rule::exists('bagian', 'kode_bagian')],
            'status' => ['required', Rule::in(['aktif', 'nonaktif'])],
            'keterangan' => ['nullable', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Pilihan "— (Teratas)" mengirim string kosong → jadikan null.
        if ($this->input('kode_induk') === '') {
            $this->merge(['kode_induk' => null]);
        }
    }

    public function attributes(): array
    {
        return [
            'kode_bagian' => 'kode bagian',
            'nama_bagian' => 'nama bagian',
            'kode_induk' => 'bagian induk',
        ];
    }
}
