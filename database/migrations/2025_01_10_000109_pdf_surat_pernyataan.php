<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Berkas PDF surat pernyataan (4 Nov 2026): diunggah petugas PPSB per VERSI, opsional,
 * bisa diunduh wali di portal. Ikut terkunci bersama versinya begitu ada wali yang menyetujui.
 * Teks (`isi`) tetap yang disetujui & di-hash; PDF hanya salinan cetak.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('term_template', function (Blueprint $table) {
            $table->string('berkas_pdf')->nullable();
            $table->string('berkas_pdf_nama')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('term_template', function (Blueprint $table) {
            $table->dropColumn(['berkas_pdf', 'berkas_pdf_nama']);
        });
    }
};
