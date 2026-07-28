<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi Wali (satu wali = satu keluarga). nama & telepon SALINAN kontak utama
 * (diisi service), jadi tidak divalidasi di sini. Kelengkapan kontak utama
 * ditegakkan WaliService (AppException).
 */
class WaliRequest extends FormRequest
{
    public const PENDAPATAN = ['di_bawah_5', 'juta_5_10', 'juta_10_15', 'juta_15_25', 'di_atas_25'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Checkbox → boolean sungguhan agar validated() & cast model konsisten.
        $this->merge(['auto_debet' => $this->boolean('auto_debet')]);
    }

    public function rules(): array
    {
        $rules = [
            'kontak_utama' => ['required', Rule::in(['ayah', 'ibu', 'wali'])],
            'auto_debet' => ['boolean'],
            'nik' => ['nullable', 'string', 'max:255'],
            'alamat' => ['nullable', 'string'],
            'status' => ['required', Rule::in(['aktif', 'nonaktif'])],
        ];
        foreach (['ayah', 'ibu', 'wali'] as $p) {
            $rules["nama_{$p}"] = ['nullable', 'string', 'max:255'];
            $rules["telepon_{$p}"] = ['nullable', 'string', 'max:255'];
            $rules["email_{$p}"] = ['nullable', 'email', 'max:255'];
            $rules["pekerjaan_{$p}"] = ['nullable', 'string', 'max:255'];
            $rules["pendapatan_{$p}"] = ['nullable', Rule::in(self::PENDAPATAN)];
        }

        return $rules;
    }
}
