<?php

namespace App\Http\Requests;

use App\Models\TipeBiaya;
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
            // Akun piutang WAJIB bila pengakuannya akrual. Sebelum ada kolom
            // `pengakuan`, terisinya akun inilah yang MENENTUKAN sifat akrual —
            // kini keduanya berdiri sendiri dan karena itu bisa berselisih.
            // Akrual tanpa akun piutang berarti jurnalnya tak punya alamat.
            'kode_coa_piutang' => ['nullable', 'required_if:pengakuan,akrual', 'string', 'exists:coa_detail,kode_coa'],
            'pengakuan' => ['required', Rule::in(['akrual', 'kas'])],
            // Hanya bermakna untuk perilaku `lain` — registrasi, uang pangkal,
            // SPP & daftar ulang punya alur penagihannya sendiri.
            'cara_tagih' => [
                Rule::requiredIf(fn () => TipeBiaya::perilakuDari($this->input('tipe')) === 'lain'),
                'nullable',
                Rule::in(['pemakaian', 'kepesertaan']),
            ],
            'kode_coa_diterima_dimuka' => ['nullable', 'string', 'exists:coa_detail,kode_coa'],
            'kode_unit' => ['required', 'string', 'exists:business_units,kode_unit'],
            // Tarif & satuan WAJIB bila ditagih menurut pemakaian — tanpa
            // keduanya kuantitas yang dicatat petugas tak bisa diubah jadi
            // rupiah, dan layarnya tak punya kata untuk menyebut "kilogram".
            'tarif_satuan' => ['nullable', 'required_if:cara_tagih,pemakaian', 'numeric', 'gt:0'],
            'nama_satuan' => ['nullable', 'required_if:cara_tagih,pemakaian', 'string', 'max:20'],
            // Kuota boleh kosong (tak ada jatah gratis); nol berarti sama.
            'kuota_gratis' => ['nullable', 'numeric', 'min:0'],
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
        foreach (['kode_coa_piutang', 'kode_coa_diterima_dimuka', 'kode_jenjang', 'cara_tagih',
            'tarif_satuan', 'nama_satuan', 'kuota_gratis'] as $f) {
            if (($data[$f] ?? '') === '') {
                $data[$f] = null;
            }
        }

        return $data;
    }
}
