<?php

namespace App\Support;

/**
 * BATAS UNGGAHAN — satu tempat untuk SELURUH isian berkas di aplikasi.
 *
 * Batasnya sengaja tidak ditulis ulang di tiap controller: pernah tersebar di
 * empat tempat dengan tiga angka berbeda (5 MB, 5 MB, 8 MB), sehingga menaikkan
 * atau menurunkannya berarti berburu satu per satu dan yang terlewat diam saja
 * sampai ada yang mengeluh berkasnya ditolak.
 *
 * Angkanya dalam KILOBYTE karena itu satuan aturan `max:` Laravel untuk berkas.
 *
 * CATATAN: ini batas APLIKASI. PHP punya batasnya sendiri (`upload_max_filesize`
 * & `post_max_size`, lazimnya 2 MB & 8 MB) — selama batas di sini lebih kecil,
 * penolakannya datang dari validasi dengan pesan yang bisa dibaca pengguna,
 * bukan dari PHP yang membuang isian formnya diam-diam.
 */
final class Unggahan
{
    /** Batas ukuran SETIAP berkas unggahan, dalam kilobyte. */
    public const MAKS_KB = 500;

    /** Berkas bukti & dokumen: dipindai/difoto atau PDF. */
    public const DOKUMEN = 'pdf,jpg,jpeg,png,webp';

    /** Berkas data pindahan (impor). */
    public const DATA = 'csv,txt';

    /**
     * Aturan validasi satu berkas. Dipakai apa adanya di controller supaya
     * batas & daftar tipenya tak pernah berbeda antar layar.
     *
     * @return list<string>
     */
    public static function aturan(string $mimes = self::DOKUMEN, bool $wajib = true): array
    {
        return [
            $wajib ? 'required' : 'nullable',
            'file',
            'max:'.self::MAKS_KB,
            'mimes:'.$mimes,
        ];
    }

    /**
     * Tipe berkas yang boleh DITAMPILKAN di peramban (audit 22 Okt 2026): hanya gambar & PDF.
     * Selain itu selalu diunduh sebagai octet-stream — berkas berkedok PDF/JPG yang sebenarnya HTML
     * tak boleh pernah dijalankan di domain ERP (XSS tersimpan terhadap sesi staf).
     */
    public const TAMPIL = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /** Tipe berkas menurut ISINYA (deteksi server), BUKAN header Content-Type kiriman peramban. */
    public static function mime(\Illuminate\Http\UploadedFile $berkas): string
    {
        return (string) ($berkas->getMimeType() ?: 'application/octet-stream');
    }

    /**
     * Sajikan berkas tersimpan dengan aman: tipe dideteksi ulang dari isi berkas; gambar/PDF boleh inline,
     * selain itu dipaksa unduh. Ditambah nosniff & CSP sandbox supaya isi berkas tak bisa menjalankan skrip.
     */
    public static function sajikan(string $disk, string $path, ?string $namaAsli, bool $inline = true): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $storage = \Illuminate\Support\Facades\Storage::disk($disk);
        abort_unless($storage->exists($path), 404);
        $mime = (string) ((new \finfo(FILEINFO_MIME_TYPE))->file($storage->path($path)) ?: 'application/octet-stream');
        $boleh = in_array($mime, self::TAMPIL, true);
        $nama = str_replace(['"', "\r", "\n", '\\'], '', (string) ($namaAsli ?: basename($path)));

        return $storage->response($path, $nama, [
            'Content-Type' => $boleh ? $mime : 'application/octet-stream',
            'Content-Disposition' => ($boleh && $inline ? 'inline' : 'attachment').'; filename="'.$nama.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; object-src 'self'",
        ]);
    }

    /** "500 KB" — untuk ditulis di layar, agar orang tahu batasnya sebelum memilih berkas. */
    public static function maksLabel(): string
    {
        return self::MAKS_KB >= 1024
            ? rtrim(rtrim(number_format(self::MAKS_KB / 1024, 1, ',', '.'), '0'), ',').' MB'
            : self::MAKS_KB.' KB';
    }

    /** Ukuran berkas terbaca manusia: 231 KB, 1,4 MB. */
    public static function ukuran(int $byte): string
    {
        if ($byte < 1024) {
            return $byte.' B';
        }
        if ($byte < 1024 * 1024) {
            return number_format($byte / 1024, 0, ',', '.').' KB';
        }

        return number_format($byte / 1024 / 1024, 1, ',', '.').' MB';
    }
}
