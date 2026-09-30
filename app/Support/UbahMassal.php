<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * UPDATE banyak baris dengan NILAI BERBEDA per baris — dalam satu kueri.
 *
 * Penerbitan massal lazim berakhir dengan "tandai tiap baris dengan tagihannya
 * masing-masing". Ditulis sebagai perulangan `->update()`, itu satu perjalanan
 * ke database per baris: 649 santri = 649 UPDATE, dan di produksi (Hostinger →
 * Neon) halaman diputus batas waktu sebelum selesai.
 *
 *     UPDATE t SET kolom = v.nilai
 *     FROM (VALUES (?, ?), (?, ?), …) AS v(kunci, nilai)
 *     WHERE t.kunci = v.kunci [AND syarat tambahan]
 *
 * Bentuk koma di FROM itu disengaja: PostgreSQL menolak merujuk tabel target
 * dari klausa JOIN (lihat CLAUDE.md).
 *
 * Hanya untuk tabel TANPA jejak audit — UPDATE mentah melewati event model.
 */
final class UbahMassal
{
    /** Batas pasangan per kueri — jauh di bawah batas 65.535 parameter PostgreSQL. */
    private const POTONGAN = 1000;

    /**
     * @param  array<int|string,int|string|null>  $peta  [nilai kunci => nilai baru]
     * @param  array<string,mixed>  $tetap  kolom lain yang diisi SAMA untuk semua baris
     * @param  string  $syarat  syarat tambahan atas tabel target (alias `t`), mis. "t.id_tagihan IS NULL"
     * @param  list<mixed>  $ikatan  nilai untuk tanda tanya di `$syarat`
     * @return int jumlah baris yang berubah
     */
    public static function perBaris(
        string $tabel,
        string $kolomKunci,
        string $kolomNilai,
        array $peta,
        string $tipeKunci = 'bigint',
        string $tipeNilai = 'bigint',
        array $tetap = [],
        string $syarat = '',
        array $ikatan = [],
    ): int {
        $berubah = 0;

        foreach (array_chunk($peta, self::POTONGAN, true) as $potong) {
            $values = [];
            $bind = [];
            foreach ($potong as $kunci => $nilai) {
                $values[] = "(?::{$tipeKunci}, ?::{$tipeNilai})";
                $bind[] = $kunci;
                $bind[] = $nilai;
            }

            $set = ["\"{$kolomNilai}\" = v.nilai"];
            $bindSet = [];
            foreach ($tetap as $kolom => $isi) {
                $set[] = "\"{$kolom}\" = ?";
                $bindSet[] = $isi;
            }

            $sql = "UPDATE \"{$tabel}\" AS t SET ".implode(', ', $set)
                .' FROM (VALUES '.implode(', ', $values).') AS v(kunci, nilai)'
                ." WHERE t.\"{$kolomKunci}\" = v.kunci"
                .($syarat !== '' ? " AND ({$syarat})" : '');

            $berubah += DB::update($sql, [...$bindSet, ...$bind, ...$ikatan]);
        }

        return $berubah;
    }
}
