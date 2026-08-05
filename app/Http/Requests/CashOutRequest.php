<?php

namespace App\Http\Requests;

use App\Exceptions\AppException;
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
            'id_perintah' => ['nullable', 'integer', 'exists:perintah_pembayaran,kode_transaksi'],
            'metode' => ['nullable', 'string', 'max:20'],
            'details' => ['required', 'array', 'min:1'],
            'details.*.id_perintah_detail' => ['nullable', 'integer', 'exists:perintah_pembayaran_detail,id'],
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

    /**
     * Baris untuk service, dipetakan per-tipe. Baris yang belum menunjuk
     * dokumennya DIBUANG — itu disengaja, supaya baris yang telanjur ditambah
     * lalu ditinggalkan tak menghalangi penyimpanan.
     *
     * TETAPI bila SEMUA baris terbuang, dokumennya jadi tanpa rincian sama
     * sekali, dan yang muncul di layar adalah "Jurnal harus memiliki minimal 2
     * baris" — aturan pembukuan yang benar tetapi tak menjelaskan apa pun
     * kepada petugas. Karena itu keadaan tersebut dihentikan di sini, dengan
     * menyebut baris mana dan apa yang belum dipilih.
     */
    public function details(): array
    {
        $out = [];
        $belum = [];
        foreach ($this->input('details', []) as $i => $d) {
            $tipe = $d['tipe'] ?? 'lainnya';
            $nomorBaris = (int) $i + 1;
            // uang_muka dikirim ke server sebagai tipe 'pengajuan' (server bedakan via jenis).
            $tipeServer = $tipe === 'uang_muka' ? 'pengajuan' : $tipe;

            $row = [
                'tipe' => $tipeServer,
                'keterangan' => $d['keterangan'] ?? null,
                // Penaut ke baris Perintah Pembayaran — dibawa untuk SEMUA tipe,
                // termasuk `lainnya` (angsuran pembiayaan memakai tipe itu).
                'id_perintah_detail' => ($d['id_perintah_detail'] ?? '') ?: null,
            ];

            if ($tipe === 'invoice') {
                if (empty($d['id_invoice'])) {
                    $belum[] = "Baris {$nomorBaris} (Pembayaran Invoice): invoice belum dipilih";

                    continue;
                }
                $row += ['id_invoice' => (int) $d['id_invoice'], 'nominal' => $d['nominal'] ?? 0, 'kode_bagian' => ($d['kode_bagian'] ?? '') ?: null];
            } elseif ($tipe === 'pengajuan' || $tipe === 'uang_muka') {
                if (empty($d['id_pengajuan'])) {
                    $label = $tipe === 'uang_muka' ? 'Pembayaran Pengajuan Uang Muka): uang muka' : 'Pelunasan Pengajuan Pembayaran): pengajuan';
                    $belum[] = "Baris {$nomorBaris} ({$label} belum dipilih";

                    continue;
                }
                $row += ['id_pengajuan' => (int) $d['id_pengajuan'], 'nominal' => $d['nominal'] ?? 0];
            } elseif ($tipe === 'inventory') {
                if (empty($d['kode_persediaan'])) {
                    $belum[] = "Baris {$nomorBaris} (Pembelian Persediaan): item persediaan belum dipilih";

                    continue;
                }
                $row += ['kode_persediaan' => $d['kode_persediaan'], 'kuantiti' => $d['kuantiti'] ?? 0, 'harga_satuan' => $d['harga_satuan'] ?? 0, 'kode_bagian' => ($d['kode_bagian'] ?? '') ?: null];
            } else { // lainnya
                if (empty($d['kode_coa'])) {
                    $belum[] = "Baris {$nomorBaris} (Beban / Akun Lainnya): akun COA belum dipilih";

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

        // Sebagian baris terbuang sementara sisanya lengkap = perilaku lama,
        // sengaja dibiarkan. Yang dihentikan hanya bila TAK ADA yang tersisa.
        if ($out === [] && $belum !== []) {
            throw new AppException(422, 'Rincian belum lengkap — '.implode('; ', $belum).'.');
        }

        return $out;
    }
}
