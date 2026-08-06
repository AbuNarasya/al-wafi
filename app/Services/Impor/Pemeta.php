<?php

namespace App\Services\Impor;

/**
 * Kontrak satu JENIS berkas impor data awal.
 *
 * Kerangka ([[ImporSaldoAwal]]) mengurus yang sama di semua jenis: membaca CSV,
 * menomori baris, menyusun pratinjau, menjalankan impor dalam satu transaksi,
 * dan menahan berkas antara pratinjau dan impor. Pemeta hanya menjawab tiga
 * hal: kolom apa yang diminta, apakah sebuah baris sah, dan bagaimana
 * menyimpannya. Menambah jenis data baru = menambah satu berkas pemeta.
 *
 * Satu-satunya pemeriksaan yang tak muat di ketiganya adalah KEKEMBARAN DI
 * DALAM BERKAS: sebuah baris tak bisa menilainya sendirian, karena baris kedua
 * yang ber-NIS sama selalu tampak sah bila dilihat terpisah. Karena itu pemeta
 * cukup menyebut kolomnya lewat kolomUnik(), dan kerangkanya yang memeriksa.
 */
interface Pemeta
{
    /** Kunci di URL & form, mis. "santri-lama". */
    public static function kunci(): string;

    /** Judul yang dibaca pemakai. */
    public static function judul(): string;

    /** Penjelasan singkat: apa yang dibuat pemeta ini, dan apa yang TIDAK. */
    public static function penjelasan(): string;

    /**
     * Kolom CSV. Kunci = nama kolom; nilai = [wajib, contoh, ket].
     *
     * @return array<string,array{wajib:bool,contoh:string,ket:string}>
     */
    public function kolom(): array;

    /**
     * Isian tambahan yang diminta SEKALI di form impor (bukan per baris) —
     * mis. akun perantara, jenis biaya tunggakan, atau tanggal cut-off.
     * Kunci = nama input. `tipe`: "pilih" (dropdown, isi `opsi`) atau "tanggal".
     *
     * @return array<string,array{label:string,tipe:string,opsi:array<string,string>,ket:string}>
     */
    public function parameter(): array;

    /**
     * Periksa parameter SEKALI sebelum baris-barisnya dibaca. Kembalikan pesan
     * kesalahan, atau null bila sudah benar.
     *
     * Dipisah dari periksa() supaya kekeliruan yang sifatnya satu berkas — mis.
     * salah memilih akun perantara — tidak muncul berulang di tiap baris.
     *
     * @param  array<string,string>  $param
     */
    public function periksaParameter(array $param): ?string;

    /**
     * Kolom yang nilainya TAK BOLEH kembar di dalam satu berkas — biasanya
     * kolom yang di database berindeks unik. Sel kosong tak dihitung kembar.
     *
     * Kembalikan `[]` bila jenis impor ini memang tak punya kunci semacam itu.
     *
     * @return list<string>
     */
    public function kolomUnik(): array;

    /**
     * Periksa satu baris. Kembalikan:
     *   ['status' => 'siap'|'lewati'|'masalah', 'alasan' => string|null]
     * "lewati" = sudah pernah diimpor, supaya berkas yang sama boleh diunggah
     * ulang setelah baris bermasalahnya diperbaiki.
     *
     * @param  array<string,string>  $baris
     * @param  array<string,string>  $param
     * @return array{status:string,alasan:?string}
     */
    public function periksa(array $baris, array $param): array;

    /**
     * Simpan baris-baris yang berstatus "siap". Dipanggil DI DALAM transaksi
     * oleh kerangka, jadi pemeta tak perlu membuka transaksinya sendiri.
     *
     * @param  list<array<string,string>>  $baris
     * @param  array<string,string>  $param
     * @return array<string,int> ringkasan apa saja yang dibuat, mis. ['santri' => 600]
     */
    public function simpan(array $baris, array $param): array;
}
