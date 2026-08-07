<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda batch pada TAGIHAN — melengkapi `…000029`.
 *
 * Mulanya "tagihan yang terbit setelah impor" dibedakan lewat perbandingan
 * waktu (`created_at > dijalankan_pada`). Itu keliru: catatan batch dibuat
 * SEBELUM barisnya disimpan, jadi tagihan yang lahir dari impor itu sendiri
 * selalu bertanggal sesudahnya. Di lari tunggal keduanya kebetulan jatuh di
 * detik yang sama sehingga lolos; di suite paralel selisih mikrodetiknya
 * terlihat, dan impor menolak membatalkan dirinya sendiri.
 *
 * Penanda yang pasti menggantikannya: tagihan dari impor bertanda batchnya,
 * tagihan yang diterbitkan petugas kemudian tidak. Tak ada lagi yang bergantung
 * pada urutan mikrodetik.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tagihan_santri', function (Blueprint $table) {
            $table->unsignedInteger('id_batch')->nullable();
            $table->foreign('id_batch')->references('id')->on('impor_batch')->nullOnDelete();
            $table->index('id_batch');
        });
    }

    public function down(): void
    {
        Schema::table('tagihan_santri', function (Blueprint $table) {
            $table->dropForeign(['id_batch']);
            $table->dropColumn('id_batch');
        });
    }
};
