<?php

namespace App\Services\Midtrans;

use App\Support\Money;

/**
 * Dua jurnal pembayaran lewat payment gateway (keputusan user 13 Okt 2026):
 *
 *  1. Penerimaan:  D Rekening Midtrans  (nominal + biaya)
 *                  K akun tujuan        nominal    (Titipan Dompet Wali / Pendapatan / Piutang)
 *                  K Titipan Biaya Admin biaya     (dipungut dari wali)
 *  2. Potongan admin Midtrans (hanya bila biaya > 0):
 *                  D Titipan Biaya Admin biaya
 *                  K Rekening Midtrans  biaya
 *
 * Saldo Rekening Midtrans sesudahnya = dana yang menunggu cair ke bank (lewat
 * Pindah Buku). Dua jurnal, bukan satu: potongan admin terbaca sebagai peristiwa
 * sendiri di buku besar rekening Midtrans, sama seperti di laporan Midtrans.
 */
final class JurnalGateway
{
    /**
     * @param  array<string,mixed>  $dasar  referensi, tanggal, keterangan, sumber_modul, id_sumber, kode_unit?
     * @return list<array<string,mixed>>  masukan untuk PostingService::postJournals
     */
    public static function susun(array $dasar, string $rekening, string $akunTujuan, string $nominal, string $biaya, string $akunBiaya): array
    {
        $ada = Money::gtZero($biaya);
        $baris = [
            ['kode_coa' => $rekening, 'debet' => Money::add($nominal, $biaya), 'kredit' => '0'],
            ['kode_coa' => $akunTujuan, 'debet' => '0', 'kredit' => $nominal],
        ];
        if ($ada) {
            $baris[] = ['kode_coa' => $akunBiaya, 'debet' => '0', 'kredit' => $biaya];
        }
        $jurnal = [$dasar + ['id_pengguna' => null, 'lines' => $baris]];

        if ($ada) {
            $jurnal[] = array_merge($dasar, [
                'id_pengguna' => null,
                'keterangan' => 'Potongan biaya admin Midtrans — '.$dasar['referensi'],
                'lines' => [
                    ['kode_coa' => $akunBiaya, 'debet' => $biaya, 'kredit' => '0'],
                    ['kode_coa' => $rekening, 'debet' => '0', 'kredit' => $biaya],
                ],
            ]);
        }

        return $jurnal;
    }
}
