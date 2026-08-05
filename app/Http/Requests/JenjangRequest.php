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
            // Batas atas 20 sekadar penjaga salah ketik; tak ada jenjang
            // pendidikan yang bertingkat sebanyak itu.
            'jumlah_tingkat' => ['nullable', 'integer', 'between:1,20'],
            // Nomor tingkat PERTAMA jenjang ini — SDTQ 1, SMP 7, SMA 10.
            // Penomorannya berkelanjutan supaya "Tingkat 8" hanya punya satu arti,
            // dan sejak NIS memuat tingkat, ambiguitasnya tak bisa dibiarkan.
            'tingkat_mulai' => ['nullable', 'integer', 'between:1,99'],
            // Tak boleh menunjuk dirinya sendiri: itu akan membuat proses
            // kenaikan berputar di jenjang yang sama tanpa pernah lulus.
            'kode_jenjang_lanjutan' => [
                'nullable', 'string', Rule::exists('jenjang', 'kode'),
                Rule::notIn([$this->route('kode') ?? $this->input('kode')]),
            ],
            // `urutan` TIDAK ada di form: ia diatur dengan menyeret baris di
            // halaman daftar (lihat UrutanTampilService).
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
        // Kosong = belum ditentukan; jangan dijadikan 0 karena "0 tingkat"
        // membuat dropdown Tingkat kosong tanpa penjelasan.
        $data['jumlah_tingkat'] = ($data['jumlah_tingkat'] ?? '') !== '' ? (int) $data['jumlah_tingkat'] : null;
        // Kosong = mulai dari 1 (jenjang yang berdiri sendiri).
        $data['tingkat_mulai'] = ($data['tingkat_mulai'] ?? '') !== '' ? (int) $data['tingkat_mulai'] : null;
        $data['kode_jenjang_lanjutan'] = ($data['kode_jenjang_lanjutan'] ?? '') !== '' ? $data['kode_jenjang_lanjutan'] : null;

        return $data;
    }
}
