<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Semua kolom jenjang dirujukkan ke master `jenjang`:
 * - santri, jenis_biaya, potongan_gelombang: kode_jenjang (nullable = umum) + FK.
 * - tarif_spp: kode_jenjang jadi FK; kolom `nama_jenjang` DIBUANG (nama diambil
 *   dari master agar tak ada dua sumber nama yang bisa berbeda).
 * - target_santri: kolom `jenjang` DIGANTI NAMA jadi `kode_jenjang` + FK, agar
 *   seragam dengan empat tabel lain.
 * Aman tanpa migrasi data: seluruh tabel ini kosong saat migrasi dibuat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('santri', function (Blueprint $table) {
            $table->foreign('kode_jenjang')->references('kode')->on('jenjang')->restrictOnDelete();
        });

        Schema::table('jenis_biaya', function (Blueprint $table) {
            $table->foreign('kode_jenjang')->references('kode')->on('jenjang')->restrictOnDelete();
        });

        Schema::table('potongan_gelombang', function (Blueprint $table) {
            $table->foreign('kode_jenjang')->references('kode')->on('jenjang')->restrictOnDelete();
        });

        Schema::table('tarif_spp', function (Blueprint $table) {
            $table->dropColumn('nama_jenjang');
            $table->foreign('kode_jenjang')->references('kode')->on('jenjang')->restrictOnDelete();
        });

        Schema::table('target_santri', function (Blueprint $table) {
            $table->renameColumn('jenjang', 'kode_jenjang');
        });
        Schema::table('target_santri', function (Blueprint $table) {
            $table->foreign('kode_jenjang')->references('kode')->on('jenjang')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('target_santri', function (Blueprint $table) {
            $table->dropForeign(['kode_jenjang']);
        });
        Schema::table('target_santri', function (Blueprint $table) {
            $table->renameColumn('kode_jenjang', 'jenjang');
        });

        Schema::table('tarif_spp', function (Blueprint $table) {
            $table->dropForeign(['kode_jenjang']);
            $table->string('nama_jenjang')->default('');
        });

        foreach (['potongan_gelombang', 'jenis_biaya', 'santri'] as $tabel) {
            Schema::table($tabel, fn (Blueprint $t) => $t->dropForeign(['kode_jenjang']));
        }
    }
};
