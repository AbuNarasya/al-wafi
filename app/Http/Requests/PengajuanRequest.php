<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validasi bentuk Pengajuan Pembayaran (jenis "pembayaran"). Aturan berat
 * (staff-only, evaluasi anggaran, akun/unit aktif) ditegakkan
 * PengajuanPembayaranService (AppException).
 */
class PengajuanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Rekening tujuan boleh dikosongkan (pembayaran tunai), tetapi bila
        // salah satu diisi ketiganya wajib: nomor rekening tanpa nama bank —
        // atau tanpa atas nama — tak bisa dipakai mentransfer, dan justru
        // menipu pembacanya karena tampak sudah terisi.
        $rekening = ['bank_tujuan', 'no_rekening_tujuan', 'atas_nama_tujuan'];
        $wajibBila = fn (string $kolom) => 'required_with:'.implode(',', array_diff($rekening, [$kolom]));

        return [
            'tanggal' => ['required', 'date'],
            'keterangan' => ['required', 'string'],
            'referensi' => ['nullable', 'string', 'max:255'],
            'bank_tujuan' => ['nullable', 'string', 'max:100', $wajibBila('bank_tujuan')],
            'no_rekening_tujuan' => ['nullable', 'string', 'max:50', 'regex:/^[0-9][0-9 .\-]*$/', $wajibBila('no_rekening_tujuan')],
            'atas_nama_tujuan' => ['nullable', 'string', 'max:150', $wajibBila('atas_nama_tujuan')],
            'simpan_rekening' => ['nullable', 'boolean'],
            'details' => ['required', 'array', 'min:1'],
            'details.*.kode_coa' => ['required', 'string', 'exists:coa_detail,kode_coa'],
            'details.*.kode_unit' => ['required', 'string', 'exists:business_units,kode_unit'],
            'details.*.nominal' => ['required', 'numeric', 'gt:0'],
            'details.*.keterangan' => ['nullable', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Isian kosong dikirim sebagai "" — disamakan ke null supaya
        // required_with tidak menganggapnya terisi.
        foreach (['bank_tujuan', 'no_rekening_tujuan', 'atas_nama_tujuan'] as $kolom) {
            if (trim((string) $this->input($kolom)) === '') {
                $this->merge([$kolom => null]);
            }
        }
    }

    public function attributes(): array
    {
        return [
            'bank_tujuan' => 'nama bank',
            'no_rekening_tujuan' => 'nomor rekening',
            'atas_nama_tujuan' => 'atas nama pemilik rekening',
        ];
    }

    public function messages(): array
    {
        return [
            'no_rekening_tujuan.regex' => 'Nomor rekening hanya boleh berisi angka (boleh dipisah spasi, titik, atau tanda hubung).',
            'bank_tujuan.required_with' => 'Nama bank wajib diisi bila rekening tujuan dicantumkan.',
            'no_rekening_tujuan.required_with' => 'Nomor rekening wajib diisi bila rekening tujuan dicantumkan.',
            'atas_nama_tujuan.required_with' => 'Atas nama pemilik rekening wajib diisi bila rekening tujuan dicantumkan.',
        ];
    }

    /** Rekening tujuan sebagai satu kesatuan; null bila tak dicantumkan. */
    public function rekeningTujuan(): array
    {
        return [
            'bank_tujuan' => $this->input('bank_tujuan'),
            'no_rekening_tujuan' => $this->input('no_rekening_tujuan'),
            'atas_nama_tujuan' => $this->input('atas_nama_tujuan'),
        ];
    }

    /** Baris rincian untuk service. */
    public function details(): array
    {
        $out = [];
        foreach ($this->input('details', []) as $d) {
            if (empty($d['kode_coa'])) {
                continue;
            }
            $out[] = [
                'kode_coa' => $d['kode_coa'],
                'kode_unit' => $d['kode_unit'],
                'nominal' => $d['nominal'],
                'keterangan' => $d['keterangan'] ?? null,
            ];
        }

        return $out;
    }
}
