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
            // Kosong = belum ditentukan; Laporan Arus Kas menampilkannya
            // sebagai kelompok tersendiri, bukan menebak kamarnya.
            'klasifikasi_arus_kas' => ['nullable', Rule::in(['operasi', 'investasi', 'pendanaan'])],
            // Hanya bermakna untuk Pendapatan & Ekuitas; kosong = tidak berlaku.
            'sifat_pembatasan' => ['nullable', Rule::in(['tanpa_pembatasan', 'dengan_pembatasan'])],
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
        return ['kode_coa' => 'kode akun', 'nama_coa' => 'nama akun', 'kode_grup' => 'grup COA', 'klasifikasi_arus_kas' => 'klasifikasi arus kas'];
    }

    public function tersimpan(): array
    {
        $data = $this->safe()->only(['nama_coa', 'kode_grup', 'jenis_saldo', 'klasifikasi_arus_kas', 'sifat_pembatasan', 'status', 'keterangan']);
        if ($this->isMethod('post')) {
            $data['kode_coa'] = $this->input('kode_coa');
        }

        return $data;
    }
}
