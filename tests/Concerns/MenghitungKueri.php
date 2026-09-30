<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Menghitung kueri yang dijalankan sebuah langkah.
 *
 * Dipakai untuk mengunci penerbitan massal: jumlah kuerinya tak boleh tumbuh
 * bersama jumlah santri. Di produksi (Hostinger → Neon) tiap kueri adalah satu
 * perjalanan jaringan, dan layar yang memutar ratusan santri sebaris-sebaris
 * diputus batas waktu sebelum sempat tampil.
 *
 * Pola ujinya: jalankan langkah yang sama untuk N santri dan N+sekian santri,
 * lalu tegaskan jumlah kuerinya SAMA.
 */
trait MenghitungKueri
{
    protected function hitungKueri(callable $langkah): int
    {
        $n = 0;
        $aktif = true;
        // Pendengar DB tak bisa dicabut, jadi ia dimatikan lewat penanda begitu
        // langkahnya selesai — supaya tak ikut menghitung langkah berikutnya.
        DB::listen(function () use (&$n, &$aktif) {
            if ($aktif) {
                $n++;
            }
        });

        try {
            $langkah();
        } finally {
            $aktif = false;
        }

        return $n;
    }
}
