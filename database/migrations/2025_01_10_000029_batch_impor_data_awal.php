<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BATCH IMPOR — satu nomor untuk seluruh baris yang lahir dari sekali impor,
 * supaya impor yang keliru bisa DIBATALKAN utuh selama belum ada apa pun yang
 * menempel padanya.
 *
 * Tanpa ini, satu berkas keliru berisi ratusan santri hanya punya dua jalan
 * keluar: membetulkan satu per satu lewat layar (dan tunggakannya bahkan tak
 * bisa dibatalkan sama sekali), atau `dummy:hapus` yang membuang SELURUH santri
 * termasuk yang benar. Keduanya buruk.
 *
 * HANYA DUA KOLOM, bukan satu per tabel tujuan: `santri` jadi jangkarnya, dan
 * tagihan, riwayat tingkat, serta riwayat NIS semuanya menggantung padanya lewat
 * `id_santri`. Yang perlu penanda sendiri cuma `wali`, karena ia dipakai bersama
 * kakak-beradik dan hanya dibuat bila teleponnya belum dikenal — wali yang sudah
 * ada sebelum impor TIDAK boleh ikut terhapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impor_batch', function (Blueprint $table) {
            $table->increments('id');
            $table->string('kunci');                 // jenis impor, mis. "santri-lama"
            $table->string('nama_berkas')->nullable();
            $table->json('ringkasan')->nullable();   // hasil simpan(): ['santri' => 202, …]
            $table->unsignedInteger('dijalankan_oleh')->nullable();
            $table->timestamp('dijalankan_pada');
            $table->timestamp('dibatalkan_pada')->nullable();
            $table->unsignedInteger('dibatalkan_oleh')->nullable();
            $table->string('alasan_batal')->nullable();
            $table->timestamps();

            $table->foreign('dijalankan_oleh')->references('id_pengguna')->on('users')->nullOnDelete();
            $table->foreign('dibatalkan_oleh')->references('id_pengguna')->on('users')->nullOnDelete();
            $table->index('kunci');
        });

        foreach (['santri', 'wali'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                // nullOnDelete: batch yang catatannya dihapus tak boleh menyeret
                // santri ikut terhapus — barisnya hanya kehilangan penandanya.
                $table->unsignedInteger('id_batch')->nullable();
                $table->foreign('id_batch')->references('id')->on('impor_batch')->nullOnDelete();
                $table->index('id_batch');
            });
        }
    }

    public function down(): void
    {
        foreach (['santri', 'wali'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->dropForeign(['id_batch']);
                $table->dropColumn('id_batch');
            });
        }

        Schema::dropIfExists('impor_batch');
    }
};
