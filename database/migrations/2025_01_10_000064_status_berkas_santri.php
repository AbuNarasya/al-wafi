<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BERKAS PSB LEWAT PORTAL WALI (keputusan user 15 Okt 2026).
 *
 * Wali mengunggah berkas anaknya dari portal; berkas registrasi = DAFTAR PERIKSA
 * yang diingatkan, bukan penghalang tahap (sama dengan proses manualnya). Staf
 * memutus status PER JENIS berkas — "diterima" boleh TANPA unggahan (berkasnya
 * diserahkan fisik), atau "perlu diganti" beserta alasannya.
 *
 *  • status_berkas_santri: satu keputusan per (santri, jenis). Tak ada baris =
 *    belum diputus → "menunggu diperiksa" bila sudah ada unggahan, "belum" bila tidak.
 *  • Pengunggah wali (dokumen_santri.diunggah_wali + diunggah_peran) sudah disiapkan
 *    migrasi 000053 — tak disentuh di sini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_berkas_santri', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_santri');
            $table->string('jenis', 50);
            $table->enum('status', ['diterima', 'perlu_diganti']);
            $table->string('catatan', 500)->nullable();
            $table->unsignedInteger('diputus_oleh')->nullable();
            $table->timestamps();

            $table->foreign('id_santri')->references('id')->on('santri')->cascadeOnDelete();
            $table->foreign('diputus_oleh')->references('id_pengguna')->on('users')->nullOnDelete();
            $table->unique(['id_santri', 'jenis']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_berkas_santri');
    }
};
