<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bahan proses KENAIKAN JENJANG, ditaruh di master supaya aturannya bisa diubah
 * dari layar — bukan dipaku di program.
 *
 *  • `jenjang.kode_jenjang_lanjutan` — jenjang berikutnya (SDTQ→SMP, SMP→SMA).
 *    Kosong berarti jenjang terakhir: santrinya menjadi alumni, bukan naik.
 *    Sengaja ditulis eksplisit, bukan ditebak dari kolom `urutan`, karena
 *    urutan akan menyesatkan begitu ada jenjang paralel (mis. putra/putri).
 *
 *  • `jalur_pendaftaran.kode_jalur_lanjutan` — jalur yang berlaku setelah naik
 *    jenjang (Reguler→Lanjutan Reguler, OSS→Lanjutan (OSS), Anak Karyawan→tetap
 *    dirinya sendiri). Ini yang menentukan tarif uang pangkal berikutnya.
 *
 *  • `jalur_pendaftaran.bebas_uang_pangkal` — santri berjalur ini TIDAK
 *    ditagih uang pangkal, baik saat mendaftar maupun saat naik jenjang.
 *    Perlu penanda tersendiri karena pembebasan bukan soal besaran tarif
 *    melainkan soal tagihannya terbit atau tidak: nominal nol ditolak, dan
 *    tanpa baris tarif pun pencarian akan turun ke tarif umum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jenjang', function (Blueprint $table) {
            $table->string('kode_jenjang_lanjutan')->nullable()->after('jumlah_tingkat');
        });
        Schema::table('jalur_pendaftaran', function (Blueprint $table) {
            $table->string('kode_jalur_lanjutan')->nullable()->after('nama');
            $table->boolean('bebas_uang_pangkal')->default(false)->after('kode_jalur_lanjutan');
        });

        // Kunci asing menunjuk tabelnya sendiri — dipasang terpisah setelah
        // kolomnya ada (pola yang sama dengan bagian.kode_induk & coa_groups).
        Schema::table('jenjang', function (Blueprint $table) {
            $table->foreign('kode_jenjang_lanjutan')->references('kode')->on('jenjang')->nullOnDelete();
        });
        Schema::table('jalur_pendaftaran', function (Blueprint $table) {
            $table->foreign('kode_jalur_lanjutan')->references('kode')->on('jalur_pendaftaran')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('jenjang', function (Blueprint $table) {
            $table->dropForeign(['kode_jenjang_lanjutan']);
            $table->dropColumn('kode_jenjang_lanjutan');
        });
        Schema::table('jalur_pendaftaran', function (Blueprint $table) {
            $table->dropForeign(['kode_jalur_lanjutan']);
            $table->dropColumn(['kode_jalur_lanjutan', 'bebas_uang_pangkal']);
        });
    }
};
