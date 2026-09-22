<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PEMBEBASAN BIAYA REGISTRASI PER SANTRI.
 *
 * Pembebasan registrasi sudah ada, tetapi hanya pada level TARIF: sel bertanda
 * "bebas" membebaskan SELURUH pendaftar pada satu (T.A, jenjang, jalur) — cocok
 * untuk jalur Anak Karyawan, tak berguna untuk satu anak yatim yang mendaftar
 * lewat jalur reguler. Petugas selama ini mengakalinya dengan menagih dulu lalu
 * mengoreksi, dan alasannya tak tercatat di mana pun.
 *
 * Empat kolom, bukan satu: tanda saja tidak cukup dipertanggungjawabkan. Yang
 * membebaskan dan kapan ikut disimpan di barisnya sendiri karena `santri` BUKAN
 * model berjejak audit — memasang jejak penuh padanya berarti merekam setiap
 * suntingan data anak, dan pembebasan uang justru akan tenggelam di antaranya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('santri', function (Blueprint $table) {
            $table->boolean('gratis_registrasi')->default(false);
            $table->text('alasan_gratis_registrasi')->nullable();
            $table->unsignedInteger('gratis_registrasi_oleh')->nullable();
            $table->timestamp('gratis_registrasi_pada')->nullable();

            // NO ACTION (bawaan): jejak siapa yang membebaskan tak boleh ikut
            // hilang hanya karena penggunanya dinonaktifkan atau dihapus.
            $table->foreign('gratis_registrasi_oleh')->references('id_pengguna')->on('users');
        });
    }

    public function down(): void
    {
        Schema::table('santri', function (Blueprint $table) {
            $table->dropForeign(['gratis_registrasi_oleh']);
            $table->dropColumn([
                'gratis_registrasi', 'alasan_gratis_registrasi',
                'gratis_registrasi_oleh', 'gratis_registrasi_pada',
            ]);
        });
    }
};
