<?php

namespace App\Http\Requests;

use App\Services\Ledger\PeringkatPengajuan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validasi Pengguna. `is_admin` SENGAJA tidak divalidasi/disimpan lewat form —
 * hak membuat pengguna tak boleh otomatis jadi hak menjadikan admin.
 * Peringkat Staff (4) & Mudir Bagian (3) WAJIB punya bagian.
 */
class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isCreate = $this->isMethod('post');
        $id = $this->route('user')?->id_pengguna;

        return [
            'username' => ['required', 'string', 'min:3', 'max:255', Rule::unique('users', 'username')->ignore($id, 'id_pengguna')],
            'nama' => ['required', 'string', 'max:255'],
            'jabatan' => ['nullable', 'string', 'max:255'],
            'password' => $isCreate ? ['required', 'string', 'min:6'] : ['nullable', 'string', 'min:6'],
            'kode_level' => ['required', 'string', Rule::exists('levels', 'kode_level')],
            'kode_bagian' => ['nullable', 'string', Rule::exists('bagian', 'kode_bagian')],
            'peringkat_pengajuan' => ['nullable', 'integer', 'between:1,4', Rule::exists('level_pengajuan', 'peringkat')],
            'tim_keuangan' => ['nullable', 'boolean'],
            'status' => ['required', Rule::in(['aktif', 'nonaktif'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('kode_bagian') === '') {
            $this->merge(['kode_bagian' => null]);
        }
        if ($this->input('peringkat_pengajuan') === '') {
            $this->merge(['peringkat_pengajuan' => null]);
        }
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $peringkat = $this->input('peringkat_pengajuan');
                $bagian = $this->input('kode_bagian');
                $wajib = [PeringkatPengajuan::STAFF => 'Staff', PeringkatPengajuan::MUDIR_BAGIAN => 'Mudir Bagian'];

                if ($peringkat !== null && isset($wajib[(int) $peringkat]) && ! $bagian) {
                    $sebutan = $wajib[(int) $peringkat];
                    $alasan = (int) $peringkat === PeringkatPengajuan::STAFF
                        ? 'Pengajuannya dibebankan ke anggaran bagiannya, jadi tanpa bagian ia tidak bisa mengajukan.'
                        : 'Ia hanya menyetujui pengajuan dari bagiannya sendiri, jadi tanpa bagian ia tidak akan pernah bisa menyetujui apa pun.';
                    $validator->errors()->add('kode_bagian', "Pengguna berperingkat {$sebutan} wajib ditempatkan di sebuah Bagian. {$alasan}");
                }
            },
        ];
    }

    public function attributes(): array
    {
        return [
            'kode_level' => 'level otorisasi keuangan',
            'kode_bagian' => 'bagian',
            'peringkat_pengajuan' => 'peringkat pengajuan',
        ];
    }
}
