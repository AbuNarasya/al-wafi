<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * NAMA SANTRI HURUF KAPITAL (keputusan user 13 Okt 2026).
 *
 * Santri baru sudah dikapitalkan oleh mutator `Santri::nama`; migrasi ini
 * menyeragamkan nama yang sudah tersimpan — huruf kapital, spasi ganda dirapikan,
 * sama persis dengan `Santri::rapikanNama()`.
 *
 * Tak bisa dibalik: bentuk huruf aslinya tidak disimpan di mana pun. `down()`
 * sengaja kosong — memulihkannya hanya mungkin dari cadangan.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE santri
               SET nama = upper(regexp_replace(btrim(nama), '\s+', ' ', 'g'))
             WHERE nama IS DISTINCT FROM upper(regexp_replace(btrim(nama), '\s+', ' ', 'g'))
        SQL);
    }

    public function down(): void
    {
        // Sengaja kosong — lihat catatan di atas.
    }
};
