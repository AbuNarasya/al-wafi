<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi Jalur Pendaftaran. kode hanya huruf/angka/underscore (dipakai sebagai
 * nilai santri.jalur), unik & tak diubah saat update.
 */
class JalurPendaftaranRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kode' => $this->isMethod('post')
                ? ['required', 'string', 'max:255', 'regex:/^[a-z0-9_]+$/i', Rule::unique('jalur_pendaftaran', 'kode')]
                : ['prohibited'],
            'nama' => ['required', 'string', 'max:255'],
            // Jalur berlaku lintas tahun ajaran — tak ada kolom T.A lagi.
            'keterangan' => ['nullable', 'string'],
            'status' => ['required', Rule::in(['aktif', 'nonaktif'])],
        ];
    }

    public function messages(): array
    {
        return ['kode.regex' => 'Kode hanya boleh huruf, angka, dan underscore.'];
    }
}
