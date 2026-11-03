<?php

namespace App\Support;

use App\Exceptions\AppException;
use App\Models\Santri;

/**
 * LAUNDRY IKHWAN / AKHWAT (keputusan user 3 Nov 2026).
 *
 * Satu modul laundry dipecah dua hak: `laundry-ikhwan` (santri L) & `laundry-akhwat` (santri P).
 * Petugas hanya melihat & menyentuh cucian santri bagiannya — papan, terima, serahkan, riwayat,
 * detail, koreksi, batal. Pembaginya jenis kelamin santri; tak ada kolom baru.
 *
 * Kuota & Tagihan, Pengaturan, Jenis Pakaian, dan Dashboard TIDAK dipecah (hak sendiri-sendiri).
 */
final class BagianLaundry
{
    /** kunci bagian → [jenis kelamin, modul hak akses, label] */
    public const BAGIAN = [
        'ikhwan' => ['jk' => 'L', 'modul' => 'laundry-ikhwan', 'label' => 'Ikhwan'],
        'akhwat' => ['jk' => 'P', 'modul' => 'laundry-akhwat', 'label' => 'Akhwat'],
    ];

    /** Untuk middleware: lolos bila berhak atas SALAH SATU bagian. */
    public const MODUL_SALAH_SATU = 'laundry-ikhwan|laundry-akhwat';

    public static function dariJk(?string $jk): ?string
    {
        foreach (self::BAGIAN as $kunci => $b) {
            if ($b['jk'] === $jk) {
                return $kunci;
            }
        }

        return null;
    }

    /** @return list<string> bagian yang boleh di-$aksi oleh pengguna yang sedang masuk */
    public static function boleh(string $aksi = 'lihat'): array
    {
        return array_keys(array_filter(self::BAGIAN, fn ($b) => Akses::boleh($b['modul'], $aksi)));
    }

    /** @return list<string> jenis kelamin santri yang boleh di-$aksi */
    public static function jkBoleh(string $aksi = 'lihat'): array
    {
        return array_map(fn ($k) => self::BAGIAN[$k]['jk'], self::boleh($aksi));
    }

    /** @throws AppException bila santri ini bukan bagian petugas yang sedang masuk */
    public static function pastikan(?Santri $santri, string $aksi): void
    {
        $bagian = self::dariJk($santri?->jenis_kelamin);
        if ($bagian === null) {
            throw new AppException(422, 'Jenis kelamin '.($santri?->nama ?? 'santri').' belum diisi, jadi belum masuk laundry ikhwan maupun akhwat. Lengkapi datanya dulu.');
        }
        if (! Akses::boleh(self::BAGIAN[$bagian]['modul'], $aksi)) {
            throw new AppException(403, "{$santri->nama} dilayani laundry ".strtolower(self::BAGIAN[$bagian]['label']).' — di luar hak Anda.');
        }
    }
}
