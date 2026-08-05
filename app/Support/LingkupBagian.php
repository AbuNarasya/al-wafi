<?php

namespace App\Support;

use App\Models\Bagian;
use App\Models\User;

/**
 * Lingkup bagian yang boleh dilihat seorang pengguna — SATU sumber untuk
 * seluruh layar yang menyaring dokumen per bagian.
 *
 * Sebelum ini aturannya disalin di beberapa tempat dan berbunyi "bagian yang
 * SAMA PERSIS", sehingga Ketua Yayasan (bagian YYS) tak pernah melihat satu pun
 * pengajuan: tak ada dokumen yang bagiannya YYS. Ia bisa menyetujui dokumen
 * yang tak pernah bisa ia temukan sendiri.
 *
 * Yang benar mengikuti struktur: sebuah bagian melihat dirinya sendiri DAN
 * seluruh bawahannya lewat `bagian.kode_induk` — sama seperti Realisasi
 * Anggaran, dan sejalan dengan rantai persetujuan yang menelusuri induk.
 */
final class LingkupBagian
{
    /**
     * Bagian $kode beserta SELURUH keturunannya. Data master yang melingkar
     * dijaga: tanpa itu, satu salah isi kode_induk menggantung permintaan.
     *
     * @return list<string>
     */
    public static function subtree(string $kode): array
    {
        $anak = [];
        foreach (Bagian::all(['kode_bagian', 'kode_induk']) as $b) {
            if ($b->kode_induk) {
                $anak[$b->kode_induk][] = $b->kode_bagian;
            }
        }

        $out = [];
        $dilewati = [];
        $tumpukan = [$kode];
        while ($tumpukan) {
            $k = array_pop($tumpukan);
            if (isset($dilewati[$k])) {
                continue;
            }
            $dilewati[$k] = true;
            $out[] = $k;
            foreach ($anak[$k] ?? [] as $c) {
                $tumpukan[] = $c;
            }
        }

        return $out;
    }

    /**
     * Lingkup bagian seorang pengguna. NULL = tanpa batas (admin & tim
     * keuangan); array kosong = tak ada bagian sama sekali, jadi ia hanya
     * berhak atas dokumennya sendiri.
     *
     * @return list<string>|null
     */
    public static function untukPengguna(User $user): ?array
    {
        if ($user->is_admin || $user->tim_keuangan) {
            return null;
        }

        return $user->kode_bagian ? self::subtree($user->kode_bagian) : [];
    }
}
