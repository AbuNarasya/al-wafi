<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi bentuk Kas Masuk. Aturan akuntansi (akun valid/aktif, dsb.) ditegakkan
 * CashInService (AppException). Jurnal: Debit Kas/Bank; Kredit tiap rincian.
 */
class CashInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tanggal' => ['required', 'date'],
            'kode_unit' => ['required', 'string', 'exists:business_units,kode_unit'],
            'kode_rekening' => ['required', 'string', 'exists:bank_accounts,kode_coa'],
            'kode_customer' => ['nullable', 'string', 'exists:customers,kode_customer'],
            'referensi' => ['nullable', 'string', 'max:255'],
            'keterangan' => ['required', 'string'],
            'details' => ['required', 'array', 'min:1'],
            'details.*.kode_coa' => ['required', 'string', 'exists:coa_detail,kode_coa'],
            'details.*.nominal' => ['required', 'numeric', 'gt:0'],
            'details.*.jenis_kas_masuk' => ['required', Rule::in(['pendapatan', 'pelunasan', 'uang_muka', 'lain'])],
            'details.*.kode_bagian' => ['nullable', 'string', 'exists:bagian,kode_bagian'],
            'details.*.keterangan' => ['nullable', 'string'],
            'details.*.kode_persediaan' => ['nullable', 'string', 'exists:inventory,kode_persediaan'],
            'details.*.kuantiti' => ['nullable', 'numeric', 'gt:0'],
        ];
    }

    /** Baris rincian yang siap dikirim ke service (buang baris tanpa akun). */
    public function details(): array
    {
        $out = [];
        foreach ($this->input('details', []) as $d) {
            if (empty($d['kode_coa'])) {
                continue;
            }
            $out[] = [
                'kode_coa' => $d['kode_coa'],
                'nominal' => $d['nominal'],
                'jenis_kas_masuk' => $d['jenis_kas_masuk'] ?? 'pendapatan',
                'kode_bagian' => ($d['kode_bagian'] ?? '') ?: null,
                'keterangan' => $d['keterangan'] ?? null,
                // Persediaan terjual (opsional → stok keluar). Service hanya
                // memproses bila keduanya terisi.
                'kode_persediaan' => ($d['kode_persediaan'] ?? '') ?: null,
                'kuantiti' => ($d['kuantiti'] ?? '') !== '' ? $d['kuantiti'] : null,
            ];
        }

        return $out;
    }
}
