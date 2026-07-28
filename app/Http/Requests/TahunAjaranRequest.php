<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi Tahun Ajaran. kode format "YYYY/YYYY" (tahun kedua = tahun pertama
 * + 1), unik, dan tak boleh diubah saat update (dirujuk tabel lain).
 */
class TahunAjaranRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kode' => $this->isMethod('post')
                ? ['required', 'string', 'regex:/^\d{4}\/\d{4}$/', Rule::unique('tahun_ajaran', 'kode')]
                : ['prohibited'],
            'tanggal_mulai' => ['nullable', 'date'],
            'tanggal_selesai' => ['nullable', 'date'],
            'status' => ['required', Rule::in(['aktif', 'nonaktif'])],
            'default_pendaftaran' => ['nullable', 'boolean'],
            'keterangan' => ['nullable', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $kode = (string) $this->input('kode');
            if ($this->isMethod('post') && preg_match('/^(\d{4})\/(\d{4})$/', $kode, $m)
                && (int) $m[2] !== (int) $m[1] + 1) {
                $v->errors()->add('kode', 'Tahun kedua harus tahun pertama + 1 (mis. 2026/2027).');
            }
        });
    }

    public function messages(): array
    {
        return ['kode.regex' => 'Format kode: YYYY/YYYY, mis. 2026/2027.'];
    }

    public function tersimpan(): array
    {
        $data = $this->safe()->except(['kode']);
        if ($this->isMethod('post')) {
            $data['kode'] = $this->input('kode');
        }
        $data['default_pendaftaran'] = $this->boolean('default_pendaftaran');
        foreach (['tanggal_mulai', 'tanggal_selesai'] as $f) {
            if (($data[$f] ?? '') === '') {
                $data[$f] = null;
            }
        }

        return $data;
    }
}
