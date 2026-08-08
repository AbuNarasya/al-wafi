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
            // Tarif satuan, nama satuan, & kuota TIDAK di sini — besarannya
            // tinggal di Matriks Tarif Layanan, sama seperti tarif biasa yang
            // tinggal di menu Tarif. Master ini identitas akuntansi saja.
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
        foreach (['kode_coa_piutang', 'kode_coa_diterima_dimuka', 'kode_jenjang', 'cara_tagih'] as $f) {
            if (($data[$f] ?? '') === '') {
                $data[$f] = null;
            }
        }

        // Dibersihkan DI SINI, bukan diandalkan pada tersembunyinya isian di
        // layar: peramban tetap mengirim isian yang di-x-show, jadi sisa nilai
        // dari pilihan sebelumnya bisa menempel pada baris yang tak mengenalnya.
        if (TipeBiaya::perilakuDari($data['tipe'] ?? null) !== 'lain') {
            $data['cara_tagih'] = null;
        }

        return $data;
    }
}
