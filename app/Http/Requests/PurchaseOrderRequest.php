<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validasi Purchase Order (dokumen komitmen — TANPA jurnal). Menjadi hutang
 * saat di-invoice. Baris: akun, kuantiti × harga.
 */
class PurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tanggal_po' => ['required', 'date'],
            'kode_vendor' => ['required', 'string', 'exists:vendors,kode_vendor'],
            'kode_unit' => ['required', 'string', 'exists:business_units,kode_unit'],
            'keterangan' => ['nullable', 'string'],
            'details' => ['required', 'array', 'min:1'],
            'details.*.kode_coa' => ['required', 'string', 'exists:coa_detail,kode_coa'],
            'details.*.kuantiti' => ['required', 'numeric', 'gt:0'],
            'details.*.harga_satuan' => ['required', 'numeric', 'gt:0'],
            'details.*.keterangan' => ['nullable', 'string'],
        ];
    }

    public function attributes(): array
    {
        return ['kode_vendor' => 'vendor', 'kode_unit' => 'unit bisnis'];
    }

    public function details(): array
    {
        $out = [];
        foreach ($this->input('details', []) as $d) {
            if (empty($d['kode_coa'])) {
                continue;
            }
            $out[] = [
                'kode_coa' => $d['kode_coa'],
                'kuantiti' => $d['kuantiti'],
                'harga_satuan' => $d['harga_satuan'],
                'keterangan' => $d['keterangan'] ?? null,
            ];
        }

        return $out;
    }
}
