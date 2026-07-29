<?php

namespace App\Services\Impor;

use App\Exceptions\AppException;
use App\Services\Impor\Pemeta\PemetaAccruePrepaid;
use App\Services\Impor\Pemeta\PemetaAsetTetap;
use App\Services\Impor\Pemeta\PemetaInvoiceVendor;
use App\Services\Impor\Pemeta\PemetaPengajuanBelumDibayar;
use App\Services\Impor\Pemeta\PemetaPinjamanBank;
use App\Services\Impor\Pemeta\PemetaPinjamanKaryawan;
use App\Services\Impor\Pemeta\PemetaSantriLama;
use App\Services\Impor\Pemeta\PemetaUangMukaOperasional;
use Illuminate\Support\Facades\DB;

/**
 * KERANGKA IMPOR DATA AWAL — satu mesin, banyak pemeta.
 *
 * Dipakai memasukkan keadaan yang sudah berjalan di luar sistem (santri lama
 * beserta tunggakannya, hutang vendor, pinjaman bank, dst.) pada saat pindah
 * ke aplikasi ini. Bukan alat pemasukan harian: jalurnya sengaja terpisah dari
 * modul biasa karena dokumen yang dibuatnya BUKAN transaksi baru.
 *
 * Alurnya selalu: unduh template → isi di Excel → unggah → PRATINJAU → Impor.
 * Pratinjau wajib; tak ada jalan langsung menulis tanpa melihat dulu.
 *
 * Aman dijalankan ulang: baris yang sudah pernah masuk ditandai "lewati", jadi
 * berkas yang sama boleh diunggah lagi setelah baris bermasalahnya dibetulkan.
 */
class ImporSaldoAwal
{
    /** @var list<class-string<Pemeta>> */
    private const PEMETA = [
        PemetaSantriLama::class,
        PemetaInvoiceVendor::class,
        PemetaPinjamanBank::class,
        PemetaPinjamanKaryawan::class,
        PemetaUangMukaOperasional::class,
        PemetaAccruePrepaid::class,
        PemetaAsetTetap::class,
        PemetaPengajuanBelumDibayar::class,
    ];

    /** @return array<string,class-string<Pemeta>> */
    public static function daftar(): array
    {
        $hasil = [];
        foreach (self::PEMETA as $kelas) {
            $hasil[$kelas::kunci()] = $kelas;
        }

        return $hasil;
    }

    public static function pemeta(string $kunci): Pemeta
    {
        $kelas = self::daftar()[$kunci] ?? null;
        if (! $kelas) {
            throw new AppException(404, "Jenis impor \"{$kunci}\" tidak dikenal.");
        }

        return app($kelas);
    }

    /**
     * Baca CSV jadi baris berkunci nama kolom.
     *
     * Menerima pemisah koma maupun titik koma — Excel berbahasa Indonesia
     * menyimpan dengan titik koma, dan berkas seperti itu kalau dipaksa dibaca
     * sebagai koma akan tampak "satu kolom" tanpa penjelasan apa pun.
     *
     * @return list<array<string,string>>
     */
    public function baca(string $path): array
    {
        $isi = file_get_contents($path);
        if ($isi === false || trim($isi) === '') {
            throw new AppException(422, 'Berkas kosong atau tidak terbaca.');
        }
        // Buang BOM UTF-8 — kalau tidak, nama kolom pertama tak pernah cocok.
        $isi = preg_replace('/^\xEF\xBB\xBF/', '', $isi);

        $barisPertama = strtok($isi, "\n");
        $pemisah = substr_count($barisPertama, ';') > substr_count($barisPertama, ',') ? ';' : ',';

        $f = fopen('php://memory', 'r+');
        fwrite($f, $isi);
        rewind($f);

        $judul = fgetcsv($f, 0, $pemisah, '"', '\\');
        if (! $judul) {
            throw new AppException(422, 'Baris judul kolom tidak ditemukan.');
        }
        $judul = array_map(fn ($h) => strtolower(trim((string) $h)), $judul);

        $rows = [];
        while (($data = fgetcsv($f, 0, $pemisah, '"', '\\')) !== false) {
            if (count(array_filter($data, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue; // baris kosong di ujung berkas
            }
            $baris = [];
            foreach ($judul as $i => $nama) {
                $baris[$nama] = trim((string) ($data[$i] ?? ''));
            }
            $rows[] = $baris;
        }
        fclose($f);

        if ($rows === []) {
            throw new AppException(422, 'Berkas hanya berisi judul kolom, tidak ada datanya.');
        }

        return $rows;
    }

    /**
     * Periksa seluruh berkas tanpa menulis apa pun.
     *
     * Sengaja TIDAK mengembalikan 600 baris mentah: yang berguna dilihat hanya
     * ringkasannya dan baris yang bermasalah beserta alasannya.
     *
     * @param  array<string,string>  $param
     * @return array{jumlah:int,siap:int,lewati:int,masalah:int,kolom_hilang:list<string>,baris_masalah:list<array{nomor:int,alasan:string,ringkas:string}>}
     */
    public function pratinjau(string $kunci, string $path, array $param = []): array
    {
        $pemeta = self::pemeta($kunci);
        if ($salah = $pemeta->periksaParameter($param)) {
            throw new AppException(422, $salah);
        }
        $rows = $this->baca($path);

        $kolomHilang = [];
        foreach ($pemeta->kolom() as $nama => $def) {
            if ($def['wajib'] && ! array_key_exists($nama, $rows[0])) {
                $kolomHilang[] = $nama;
            }
        }
        if ($kolomHilang !== []) {
            return [
                'jumlah' => count($rows), 'siap' => 0, 'lewati' => 0, 'masalah' => count($rows),
                'kolom_hilang' => $kolomHilang, 'baris_masalah' => [],
            ];
        }

        $siap = $lewati = 0;
        $masalah = [];
        foreach ($rows as $i => $baris) {
            $p = $pemeta->periksa($baris, $param);
            match ($p['status']) {
                'siap' => $siap++,
                'lewati' => $lewati++,
                default => $masalah[] = [
                    'nomor' => $i + 2, // +2: baris 1 judul, hitungan Excel mulai 1
                    'alasan' => $p['alasan'] ?? 'Tidak sah.',
                    'ringkas' => $this->ringkas($baris),
                ],
            };
        }

        return [
            'jumlah' => count($rows), 'siap' => $siap, 'lewati' => $lewati,
            'masalah' => count($masalah), 'kolom_hilang' => [],
            'baris_masalah' => array_slice($masalah, 0, 200),
        ];
    }

    /**
     * Jalankan impor. Hanya baris "siap" yang ditulis; "lewati" dan "masalah"
     * dilewatkan — bukan menggagalkan seluruh berkas, supaya sisanya bisa
     * diperbaiki lalu diunggah ulang.
     *
     * @param  array<string,string>  $param
     * @return array{tersimpan:array<string,int>,siap:int,lewati:int,masalah:int}
     */
    public function jalankan(string $kunci, string $path, array $param = []): array
    {
        $pemeta = self::pemeta($kunci);
        if ($salah = $pemeta->periksaParameter($param)) {
            throw new AppException(422, $salah);
        }
        $rows = $this->baca($path);

        $siap = [];
        $lewati = $masalah = 0;
        foreach ($rows as $baris) {
            $p = $pemeta->periksa($baris, $param);
            match ($p['status']) {
                'siap' => $siap[] = $baris,
                'lewati' => $lewati++,
                default => $masalah++,
            };
        }

        if ($siap === []) {
            throw new AppException(422, 'Tidak ada baris yang siap diimpor.');
        }

        $tersimpan = DB::transaction(fn () => $pemeta->simpan($siap, $param));

        return ['tersimpan' => $tersimpan, 'siap' => count($siap), 'lewati' => $lewati, 'masalah' => $masalah];
    }

    /** Isi CSV template: baris judul + satu baris contoh. */
    public function template(string $kunci): string
    {
        $kolom = self::pemeta($kunci)->kolom();

        $f = fopen('php://memory', 'r+');
        fputcsv($f, array_keys($kolom), ',', '"', '\\');
        fputcsv($f, array_map(fn ($d) => $d['contoh'], $kolom), ',', '"', '\\');
        rewind($f);
        $isi = stream_get_contents($f);
        fclose($f);

        return "\xEF\xBB\xBF".$isi; // BOM supaya Excel membaca UTF-8 dengan benar
    }

    /** Cuplikan baris untuk ditampilkan di daftar masalah. */
    private function ringkas(array $baris): string
    {
        $ambil = array_slice(array_filter($baris, fn ($v) => $v !== ''), 0, 3);

        return implode(' · ', $ambil);
    }
}
