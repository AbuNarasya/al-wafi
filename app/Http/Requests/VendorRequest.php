<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class VendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kode_vendor' => $this->isMethod('post')
                ? ['required', 'string', 'max:255', Rule::unique('vendors', 'kode_vendor')]
                : ['prohibited'],
            'nama_vendor' => ['required', 'string', 'max:255'],
            'kode_jenis_vendor' => ['required', 'string', Rule::exists('vendor_types', 'kode_jenis_vendor')],
            'alamat' => ['nullable', 'string'],
            'telepon' => ['nullable', 'string', 'max:255'],
            'metode_pembayaran' => ['required', Rule::in(['tunai', 'termin'])],
            'termin_hari' => ['nullable', 'integer', 'min:0'],
            'no_rekening' => ['nullable', 'string', 'max:255'],
            'bank' => ['nullable', 'string', 'max:255'],
            'atas_nama' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(['aktif', 'nonaktif'])],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $v) {
                if ($this->input('metode_pembayaran') === 'termin' && ! $this->input('termin_hari')) {
                    $v->errors()->add('termin_hari', 'Termin (hari) wajib diisi untuk metode pembayaran Termin.');
                }
            },
        ];
    }

    public function tersimpan(): array
    {
        $fields = ['nama_vendor', 'kode_jenis_vendor', 'alamat', 'telepon', 'metode_pembayaran', 'termin_hari', 'no_rekening', 'bank', 'atas_nama', 'status'];
        $data = $this->safe()->only($fields);
        if ($this->isMethod('post')) {
            $data['kode_vendor'] = $this->input('kode_vendor');
        }

        return $data;
    }
}
