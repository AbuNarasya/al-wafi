<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validasi Invoice Vendor (pengakuan hutang usaha). Jurnal: Debit tiap akun
 * rincian (kuantiti × harga), Kredit akun hutang sebesar total.
 */
class InvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_po' => ['nullable', 'integer', 'exists:purchase_orders,id_po'],
            'nomor_invoice' => ['required', 'string', 'max:255'],
            'tanggal_invoice' => ['required', 'date'],
            'tanggal_jatuh_tempo' => ['required', 'date', 'after_or_equal:tanggal_invoice'],
            'kode_vendor' => ['required', 'string', 'exists:vendors,kode_vendor'],
            'kode_unit' => ['required', 'string', 'exists:business_units,kode_unit'],
            'kode_coa_hutang' => ['required', 'string', 'exists:coa_detail,kode_coa'],
            'keterangan' => ['nullable', 'string'],
            'details' => ['required', 'array', 'min:1'],
            'details.*.kode_coa' => ['required', 'string', 'exists:coa_detail,kode_coa'],
            'details.*.kuantiti' => ['required', 'numeric', 'gt:0'],
            'details.*.harga_satuan' => ['required', 'numeric', 'gt:0'],
            'details.*.kode_bagian' => ['nullable', 'string', 'exists:bagian,kode_bagian'],
            'details.*.keterangan' => ['nullable', 'string'],
            // Perlakuan aset: '' (bukan aset), '__new__' (buat draft), atau kode_aset.
            'details.*.aset_pilih' => ['nullable', 'string'],
        ];
    }

    public function attributes(): array
    {
        return ['kode_coa_hutang' => 'akun hutang usaha', 'kode_vendor' => 'vendor'];
    }

    /** Baris rincian untuk service (buang baris tanpa akun). */
    public function details(): array
    {
        $out = [];
        foreach ($this->input('details', []) as $d) {
            if (empty($d['kode_coa'])) {
                continue;
            }
            $aset = $d['aset_pilih'] ?? '';
            $out[] = [
                'kode_coa' => $d['kode_coa'],
                'kuantiti' => $d['kuantiti'],
                'harga_satuan' => $d['harga_satuan'],
                'kode_bagian' => ($d['kode_bagian'] ?? '') ?: null,
                'keterangan' => $d['keterangan'] ?? null,
                // Perlakuan aset (kapitalisasi): __new__ → buat draft; kode aset → tambah nilai.
                'buat_aset' => $aset === '__new__',
                'kode_aset' => ($aset && $aset !== '__new__') ? $aset : null,
            ];
        }

        return $out;
    }
}
