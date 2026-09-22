<?php

namespace App\Http\Requests;

use App\Models\LevelPengajuan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validasi Pengguna. `is_admin` SENGAJA tidak divalidasi/disimpan lewat form —
 * hak membuat pengguna tak boleh otomatis jadi hak menjadikan admin.
 * Level pengajuan bertanda TERIKAT BAGIAN mewajibkan penggunanya punya bagian.
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
            // TANPA batas atas: jumlah level pengajuan kini ditentukan pesantren
            // lewat masternya, jadi keberadaan barisnya yang menjadi satu-satunya
            // syarat. Dulu `between:1,4` diam-diam menolak level kelima yang
            // sudah dibuat orang di masternya.
            'peringkat_pengajuan' => ['nullable', 'integer', 'min:1', Rule::exists('level_pengajuan', 'peringkat')],
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
                // Dulu dua peringkat disebut satu per satu; kini ditanyakan pada
                // masternya, supaya level buatan sendiri ikut terjaga.
                $level = $peringkat === null ? null : LevelPengajuan::find((int) $peringkat);

                if ($level?->terikat_bagian && ! $bagian) {
                    $validator->errors()->add('kode_bagian', "Level pengajuan \"{$level->nama}\" terikat pada sebuah Bagian, "
                        .'jadi penggunanya wajib ditempatkan di salah satunya. Pengajuan dibebankan ke anggaran bagian, '
                        .'dan penyetujuannya pun hanya menjangkau bagian itu — tanpa bagian, ia tak bisa mengajukan '
                        .'maupun menyetujui apa pun.');
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
