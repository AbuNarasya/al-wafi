<?php

namespace App\Services\Modules;

use App\Models\MutasiDompet;
use App\Models\Penjualan;
use App\Models\ReturPenjualan;
use App\Models\TransaksiDompetToko;
use App\Support\Money;

/**
 * RINCIAN BELANJA KARTU untuk wali (keputusan user 20 Okt 2026): daftar barang di balik satu mutasi `jajan`.
 *
 * Satu bentuk untuk kedua jalur:
 *  • instans Wafi Mart MENGIRIM rincian ini bersama potong/kembalikan lewat penghubung, dan ERP Al Wafi
 *    menyimpannya sebagai SNAPSHOT di `transaksi_dompet_toko.rincian` (nama barang saat itu, seperti struk);
 *  • kasir lokal di instans Al Wafi dibaca langsung dari struknya.
 * Hanya yang pantas dilihat wali: nama, jumlah, satuan, harga, diskon, subtotal — TANPA harga pokok/stok.
 *
 * Bentuk: {struk, toko, waktu, jenis: belanja|retur|batal, barang: [{nama, jumlah, satuan, harga, diskon, subtotal}],
 *          diskon_struk, total, struk_asal?}
 */
class RincianBelanja
{
    public const MAKS_BARANG = 200;

    public static function dariPenjualan(Penjualan $jual, ?string $namaToko = null): array
    {
        $jual->loadMissing('details.persediaan', 'shift.lokasi');

        return [
            'struk' => $jual->nomor, 'toko' => $namaToko ?? $jual->shift?->lokasi?->nama,
            'waktu' => $jual->waktu?->toIso8601String(), 'jenis' => 'belanja',
            'barang' => $jual->details->map(fn ($d) => [
                'nama' => (string) ($d->persediaan?->nama_persediaan ?? $d->kode_persediaan),
                'jumlah' => Money::of($d->kuantiti_input, 4), 'satuan' => (string) $d->satuan,
                'harga' => Money::of($d->harga_satuan), 'diskon' => Money::of($d->diskon ?? 0), 'subtotal' => Money::of($d->total),
            ])->take(self::MAKS_BARANG)->values()->all(),
            'diskon_struk' => Money::of($jual->diskon_struk ?? 0), 'total' => Money::of($jual->total),
        ];
    }

    public static function dariRetur(ReturPenjualan $retur, Penjualan $jual, ?string $namaToko = null): array
    {
        $retur->loadMissing('details.persediaan', 'details.baris');
        $jual->loadMissing('shift.lokasi');

        return [
            'struk' => $retur->nomor, 'toko' => $namaToko ?? $jual->shift?->lokasi?->nama,
            'waktu' => $retur->waktu?->toIso8601String(), 'jenis' => 'retur', 'struk_asal' => $jual->nomor,
            'barang' => $retur->details->map(fn ($d) => [
                'nama' => (string) ($d->persediaan?->nama_persediaan ?? $d->kode_persediaan),
                'jumlah' => Money::of($d->kuantiti_input, 4), 'satuan' => (string) ($d->baris?->satuan ?? ''),
                'harga' => Money::of($d->baris?->harga_satuan ?? 0), 'diskon' => '0.00', 'subtotal' => Money::of($d->nilai),
            ])->take(self::MAKS_BARANG)->values()->all(),
            'diskon_struk' => '0.00', 'total' => Money::of($retur->total),
        ];
    }

    /**
     * Rincian kiriman instans lain disaring ke bentuk baku sebelum disimpan: hanya kolom yang dikenal,
     * teks dipotong, angka dibakukan. Kiriman rusak/kosong = null (transaksi tetap jalan tanpa rincian).
     */
    public static function bersihkan(mixed $r): ?array
    {
        if (! is_array($r) || ! is_array($r['barang'] ?? null)) {
            return null;
        }
        $teks = fn ($v, int $maks) => $v === null ? null : mb_substr(trim((string) $v), 0, $maks);
        $angka = fn ($v, int $skala = 2) => is_numeric($v) ? Money::of($v, $skala) : Money::of(0, $skala);
        $barang = [];
        foreach (array_slice($r['barang'], 0, self::MAKS_BARANG) as $b) {
            if (! is_array($b) || trim((string) ($b['nama'] ?? '')) === '') {
                continue;
            }
            $barang[] = [
                'nama' => $teks($b['nama'], 150), 'jumlah' => $angka($b['jumlah'] ?? 0, 4), 'satuan' => (string) $teks($b['satuan'] ?? '', 30),
                'harga' => $angka($b['harga'] ?? 0), 'diskon' => $angka($b['diskon'] ?? 0), 'subtotal' => $angka($b['subtotal'] ?? 0),
            ];
        }
        if (! $barang) {
            return null;
        }

        return array_filter([
            'struk' => $teks($r['struk'] ?? null, 64), 'toko' => $teks($r['toko'] ?? null, 100),
            'waktu' => $teks($r['waktu'] ?? null, 40),
            'jenis' => in_array($r['jenis'] ?? null, ['belanja', 'retur'], true) ? $r['jenis'] : 'belanja',
            'struk_asal' => $teks($r['struk_asal'] ?? null, 64),
            'barang' => $barang, 'diskon_struk' => $angka($r['diskon_struk'] ?? 0), 'total' => $angka($r['total'] ?? 0),
        ], fn ($v) => $v !== null);
    }

    /**
     * Rincian di balik satu mutasi dompet (sisi ERP Al Wafi). null = bukan belanja / rincian tak tersedia
     * (transaksi sebelum fitur ini ada, atau pembatalan lokal yang tak menautkan mutasinya).
     */
    public static function untukMutasi(MutasiDompet $m): ?array
    {
        if ($m->jenis !== 'jajan') {
            return null;
        }

        // Lewat penghubung (instans Wafi Mart).
        if ($t = TransaksiDompetToko::where('id_mutasi_dompet', $m->id)->first()) {
            if ($t->rincian) {
                return $t->rincian;
            }
            // Pembatalan utuh tak membawa rincian sendiri: tampilkan struk asalnya, ditandai batal.
            if ($t->jenis === 'kembalikan' && $t->nomor_asal
                && ($asal = TransaksiDompetToko::where('nomor_toko', $t->nomor_asal)->value('rincian'))) {
                $asal = is_array($asal) ? $asal : json_decode((string) $asal, true);

                return ['jenis' => 'batal', 'struk_asal' => $asal['struk'] ?? null, 'total' => Money::of($t->nominal)] + $asal;
            }

            return null;
        }

        // Kasir lokal (instans Al Wafi).
        if ($jual = Penjualan::where('id_mutasi_dompet', $m->id)->first()) {
            return self::dariPenjualan($jual);
        }
        if ($retur = ReturPenjualan::where('id_mutasi_dompet', $m->id)->first()) {
            return self::dariRetur($retur, Penjualan::findOrFail($retur->id_penjualan));
        }

        return null;
    }
}
