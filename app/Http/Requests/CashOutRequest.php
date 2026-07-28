<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi Kas Keluar (Payment Voucher) multi-tipe: lainnya (beban/akun),
 * invoice (bayar hutang vendor), pengajuan (pelunasan pengajuan pembayaran/uang
 * muka), inventory (pembelian persediaan). Aturan akuntansi & nominal ditegakkan
 * CashOutService (AppException); di sini validasi bentuk longgar per-tipe.
 */
class CashOutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tanggal' => ['required', 'date'],
            'kode_rekening' => ['required', 'string', 'exists:bank_accounts,kode_coa'],
            // Unit WAJIB kecuali semua baris pengajuan (bawa unit sendiri) → service enforces.
            'kode_unit' => ['nullable', 'string', 'exists:business_units,kode_unit'],
            'kode_vendor' => ['nullable', 'string', 'exists:vendors,kode_vendor'],
            'referensi' => ['nullable', 'string', 'max:255'],
            'keterangan' => ['required', 'string'],
            'id_bank_loan' => ['nullable', 'integer', 'exists:bank_loans,id'],
            'details' => ['required', 'array', 'min:1'],
            'details.*.tipe' => ['required', Rule::in(['lainnya', 'invoice', 'pengajuan', 'uang_muka', 'inventory'])],
            'details.*.kode_coa' => ['nullable', 'string', 'exists:coa_detail,kode_coa'],
            'details.*.id_invoice' => ['nullable', 'integer', 'exists:invoices,id_invoice'],
            'details.*.id_pengajuan' => ['nullable', 'integer'],
            'details.*.kode_persediaan' => ['nullable', 'string', 'exists:inventory,kode_persediaan'],
            'details.*.kuantiti' => ['nullable', 'numeric', 'gt:0'],
            'details.*.harga_satuan' => ['nullable', 'numeric', 'gt:0'],
            'details.*.nominal' => ['nullable', 'numeric', 'gte:0'],
            'details.*.kode_bagian' => ['nullable', 'string', 'exists:bagian,kode_bagian'],
            'details.*.keterangan' => ['nullable', 'string'],
            // Perlakuan aset (baris lainnya): '' | '__new__' | kode_aset.
            'details.*.aset_pilih' => ['nullable', 'string'],
        ];
    }

    /** Baris untuk service, dipetakan per-tipe (baris kosong dibuang). */
    public function details(): array
    {
        $out = [];
        foreach ($this->input('details', []) as $d) {
            $tipe = $d['tipe'] ?? 'lainnya';
            // uang_muka dikirim ke server sebagai tipe 'pengajuan' (server bedakan via jenis).
            $tipeServer = $tipe === 'uang_muka' ? 'pengajuan' : $tipe;

            $row = ['tipe' => $tipeServer, 'keterangan' => $d['keterangan'] ?? null];

            if ($tipe === 'invoice') {
                if (empty($d['id_invoice'])) {
                    continue;
                }
                $row += ['id_invoice' => (int) $d['id_invoice'], 'nominal' => $d['nominal'] ?? 0, 'kode_bagian' => ($d['kode_bagian'] ?? '') ?: null];
            } elseif ($tipe === 'pengajuan' || $tipe === 'uang_muka') {
                if (empty($d['id_pengajuan'])) {
                    continue;
                }
                $row += ['id_pengajuan' => (int) $d['id_pengajuan'], 'nominal' => $d['nominal'] ?? 0];
            } elseif ($tipe === 'inventory') {
                if (empty($d['kode_persediaan'])) {
                    continue;
                }
                $row += ['kode_persediaan' => $d['kode_persediaan'], 'kuantiti' => $d['kuantiti'] ?? 0, 'harga_satuan' => $d['harga_satuan'] ?? 0, 'kode_bagian' => ($d['kode_bagian'] ?? '') ?: null];
            } else { // lainnya
                if (empty($d['kode_coa'])) {
                    continue;
                }
                $aset = $d['aset_pilih'] ?? '';
                $row += [
                    'kode_coa' => $d['kode_coa'],
                    'nominal' => $d['nominal'] ?? 0,
                    'kode_bagian' => ($d['kode_bagian'] ?? '') ?: null,
                    // Perlakuan aset (kapitalisasi): __new__ → buat draft; kode aset → tambah nilai.
                    'buat_aset' => $aset === '__new__',
                    'kode_aset' => ($aset && $aset !== '__new__') ? $aset : null,
                ];
            }
            $out[] = $row;
        }

        return $out;
    }
}
