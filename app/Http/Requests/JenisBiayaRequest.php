<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi Jenis Biaya (registrasi/uang_pangkal/spp/lain). kode_coa_piutang null
 * = cash basis. Keberadaan akun & unit divalidasi ulang JenisBiayaService.
 */
class JenisBiayaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kode' => $this->isMethod('post')
                ? ['required', 'string', 'max:255', Rule::unique('jenis_biaya', 'kode')]
                : ['prohibited'],
            'nama' => ['required', 'string', 'max:255'],
            // Tahun ajaran, jalur, & nominal TIDAK ADA di sini lagi: ketiganya
            // dimensi TARIF, dan tarif kini diatur di menu Tarif.
            // Tipe kini master (lihat TipeBiaya) — bukan lagi daftar tetap di sini.
            'tipe' => ['required', Rule::exists('tipe_biaya', 'kode')->where('status', 'aktif')],
            'kode_jenjang' => ['nullable', 'string', 'max:255'],
            'kode_coa_pendapatan' => ['required', 'string', 'exists:coa_detail,kode_coa'],
            'kode_coa_piutang' => ['nullable', 'string', 'exists:coa_detail,kode_coa'],
            'kode_coa_diterima_dimuka' => ['nullable', 'string', 'exists:coa_detail,kode_coa'],
            'kode_unit' => ['required', 'string', 'exists:business_units,kode_unit'],
            'berulang' => ['nullable', 'boolean'],
            'status' => ['required', Rule::in(['aktif', 'nonaktif'])],
        ];
    }

    public function tersimpan(): array
    {
        $data = $this->safe()->except(['kode']);
        if ($this->isMethod('post')) {
            $data['kode'] = $this->input('kode');
        }
        $data['berulang'] = $this->boolean('berulang');
        foreach (['kode_coa_piutang', 'kode_coa_diterima_dimuka', 'kode_jenjang'] as $f) {
            if (($data[$f] ?? '') === '') {
                $data[$f] = null;
            }
        }

        return $data;
    }
}
