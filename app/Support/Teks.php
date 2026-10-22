<?php

namespace App\Support;

/**
 * Kalimat yang BELUM diterjemahkan: kunci (teks Indonesia, lihat lang/*.json) + isiannya. Dipakai kabar wali
 * (tahap 2 portal tiga bahasa, 22 Okt 2026): pengirim kabar tak tahu bahasa penerimanya, jadi menyerahkan
 * `Teks::k('Pembayaran :nomor ditolak: :alasan', [...])`, dan App\Services\PortalWali\KabarWali menyusunnya
 * PER ORANG dalam bahasa pilihannya (tabel `bahasa_wali`).
 *
 * Isian boleh berupa Teks lagi (mis. label status) — ikut diterjemahkan. Isian biasa (nominal, nama tagihan,
 * alasan yang diketik staf) tampil apa adanya. Bila dipakai sebagai string biasa, hasilnya bahasa Indonesia.
 */
final class Teks implements \Stringable
{
    /** @param  array<string, string|int|float|self|null>  $isian */
    public function __construct(public readonly string $kunci, public readonly array $isian = []) {}

    /** @param  array<string, string|int|float|self|null>  $isian */
    public static function k(string $kunci, array $isian = []): self
    {
        return new self($kunci, $isian);
    }

    public function dalam(string $bahasa): string
    {
        $isian = array_map(fn ($v) => $v instanceof self ? $v->dalam($bahasa) : (string) $v, $this->isian);

        return (string) __($this->kunci, $isian, $bahasa);
    }

    /** Teks biasa atau Teks → string dalam bahasa itu. */
    public static function jadi(string|self $teks, string $bahasa): string
    {
        return $teks instanceof self ? $teks->dalam($bahasa) : $teks;
    }

    public function __toString(): string
    {
        return $this->dalam(BahasaPortal::BAWAAN);
    }
}
