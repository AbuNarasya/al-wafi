<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi master Jenjang. `kode` hanya huruf/angka/underscore (dipakai sebagai
 * nilai kode_jenjang di tabel lain), unik, dan tak boleh diubah saat update.
 */
class JenjangRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kode' => $this->isMethod('post')
                ? ['required', 'string', 'max:255', 'regex:/^[a-z0-9_]+$/i', Rule::unique('jenjang', 'kode')]
                : ['prohibited'],
            'nama' => ['required', 'string', 'max:255'],
            'urutan' => ['nullable', 'integer', 'between:0,999'],
            'status' => ['required', Rule::in(['aktif', 'nonaktif'])],
            'keterangan' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return ['kode.regex' => 'Kode hanya boleh huruf, angka, dan underscore (mis. SD, SMP, MTs).'];
    }

    public function tersimpan(): array
    {
        $data = $this->safe()->except(['kode']);
        if ($this->isMethod('post')) {
            $data['kode'] = $this->input('kode');
        }
        $data['urutan'] = (int) ($data['urutan'] ?? 0);

        return $data;
    }
}
