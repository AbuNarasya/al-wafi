<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Penyimpan URUTAN TAMPIL untuk master ber-kolom `urutan` (jenjang, tipe biaya,
 * sumber informasi, jalur pendaftaran).
 *
 * Satu tempat untuk keempatnya supaya aturannya tak bercabang: nomor urut
 * SELALU dirapikan ulang menjadi 1..n menurut susunan yang dikirim layar. Tanpa
 * perapian itu, nomor hasil seretan akan berlubang dan bertabrakan — dan dua
 * baris ber-`urutan` sama kembali diurutkan oleh `kode`, yang persis masalah
 * yang ingin dihilangkan.
 *
 * Baris yang TIDAK ikut terkirim (mis. tersembunyi filter di layar) tidak
 * dibuang: ia diletakkan sesudah baris yang dikirim, dengan urutan lamanya
 * dipertahankan. Jadi menyeret sambil memfilter tak pernah menghapus posisi
 * baris yang sedang tak terlihat.
 */
class UrutanTampilService
{
    /**
     * @param  class-string<Model>  $kelas  model master yang punya kolom `urutan`
     * @param  array<int,string>  $kodeUrut  kunci baris menurut susunan barunya
     * @return int  banyak baris yang nomornya benar-benar berubah
     */
    public function simpan(string $kelas, array $kodeUrut): int
    {
        /** @var Model $contoh */
        $contoh = new $kelas;
        $pk = $contoh->getKeyName();

        $kodeUrut = array_values(array_unique(array_map('strval', $kodeUrut)));
        if ($kodeUrut === []) {
            throw new AppException(422, 'Tidak ada baris yang dikirim untuk diurutkan.');
        }

        $semua = $kelas::orderBy('urutan')->orderBy($pk)->get();
        $adaKode = $semua->pluck($pk)->map('strval')->flip();

        $asing = array_values(array_filter($kodeUrut, fn ($k) => ! isset($adaKode[$k])));
        if ($asing !== []) {
            throw new AppException(422, 'Baris tidak dikenal: '.implode(', ', $asing).'.');
        }

        // Yang dikirim lebih dulu, sisanya (tersembunyi filter) menyusul dengan
        // urutan lamanya — `$semua` sudah terurut, jadi cukup disaring.
        $posisi = array_flip($kodeUrut);
        $susunan = array_merge(
            $kodeUrut,
            $semua->pluck($pk)->map('strval')->reject(fn ($k) => isset($posisi[$k]))->values()->all()
        );

        return DB::transaction(function () use ($semua, $susunan) {
            $lama = $semua->keyBy(fn ($m) => (string) $m->getKey());
            $berubah = 0;
            foreach ($susunan as $i => $kode) {
                $baris = $lama[$kode];
                if ((int) $baris->urutan === $i + 1) {
                    continue;
                }
                $baris->update(['urutan' => $i + 1]);
                $berubah++;
            }

            return $berubah;
        });
    }

    /**
     * Nomor untuk baris BARU: paling bawah. Master ini kecil dan barisnya
     * ditambah sesekali, jadi menghitung ulang saat menyimpan lebih murah
     * daripada menyimpan penghitung sendiri.
     *
     * STATIC supaya service master (jenjang, tipe biaya, …) tak perlu menyuntik
     * apa pun untuk memakainya — perhitungannya tak menyimpan keadaan.
     *
     * @param  class-string<Model>  $kelas
     */
    public static function berikutnya(string $kelas): int
    {
        return ((int) $kelas::max('urutan')) + 1;
    }
}
