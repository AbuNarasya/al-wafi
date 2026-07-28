<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi Level Otorisasi Keuangan. kode_level hanya wajib & unik saat create;
 * saat update PK tidak diubah (di-abaikan). max_transaksi null = tak terbatas.
 */
class LevelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Otorisasi ditangani middleware hakakses pada route.
    }

    public function rules(): array
    {
        $isCreate = $this->isMethod('post');

        return [
            'kode_level' => $isCreate
                ? ['required', 'string', 'max:255', Rule::unique('levels', 'kode_level')]
                : ['prohibited'],
            'nama_level' => ['required', 'string', 'max:255'],
            'max_transaksi' => ['nullable', 'numeric', 'min:0'],
            'keterangan' => ['nullable', 'string'],
            'status' => ['required', Rule::in(['aktif', 'nonaktif'])],
        ];
    }

    public function attributes(): array
    {
        return [
            'kode_level' => 'kode level',
            'nama_level' => 'nama level',
            'max_transaksi' => 'maksimal transaksi',
        ];
    }

    /**
     * Nilai tersimpan: max_transaksi kosong → null (tak terbatas).
     */
    public function tersimpan(): array
    {
        $data = $this->safe()->only(['nama_level', 'max_transaksi', 'keterangan', 'status']);
        if ($this->isMethod('post')) {
            $data['kode_level'] = $this->input('kode_level');
        }
        $data['max_transaksi'] = ($data['max_transaksi'] ?? '') === '' ? null : $data['max_transaksi'];

        return $data;
    }
}
