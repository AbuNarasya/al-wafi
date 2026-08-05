<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `santri.kode_jenjang` menjadi WAJIB.
 *
 * Aturannya sudah ditegakkan dua pintu masuk — form pendaftaran & impor santri
 * lama sama-sama menolak baris tanpa jenjang — tetapi kolomnya sendiri masih
 * membolehkan kosong. Artinya pintu ketiga yang dibuat kelak (skrip perbaikan,
 * seeder, impor lain) bisa menembusnya tanpa ada yang menahan.
 *
 * Sejak potongan gelombang & tarif sama-sama menuntut jenjang COCOK PERSIS,
 * santri tanpa jenjang tak akan pernah bisa ditagih apa pun — ia hanya akan
 * menggantung tanpa pesan yang menjelaskan sebabnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Diperiksa lebih dulu supaya kegagalannya bisa dibaca: galat bawaan
        // PostgreSQL hanya menyebut "column contains null values" tanpa memberi
        // tahu berapa baris dan siapa saja.
        $bermasalah = DB::table('santri')->whereNull('kode_jenjang')->count();
        if ($bermasalah > 0) {
            $contoh = DB::table('santri')->whereNull('kode_jenjang')
                ->limit(5)->pluck('nama')->implode(', ');

            throw new RuntimeException(
                "Migrasi dibatalkan — {$bermasalah} santri belum berjenjang: {$contoh}"
                .($bermasalah > 5 ? ' …' : '').'. Lengkapi jenjangnya lewat menu Santri lebih dulu; '
                .'menebaknya di sini akan menempatkan santri di jenjang yang salah.'
            );
        }

        DB::statement('ALTER TABLE santri ALTER COLUMN kode_jenjang SET NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE santri ALTER COLUMN kode_jenjang DROP NOT NULL');
    }
};
