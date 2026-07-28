<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rujukan tahun_ajaran (FK ke tahun_ajaran.kode):
 * - jenis_biaya & jalur_pendaftaran: kolom BARU, wajib (keputusan user: tiap
 *   baris terikat satu TA). Aman NOT NULL — kedua tabel kosong pasca reset.
 * - santri: kolom BARU, nullable di skema (santri lama tanpa TA), wajib saat
 *   registrasi (ditegakkan SantriService).
 * - potongan_gelombang & target_santri: kolom lama (teks bebas) diberi FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jenis_biaya', function (Blueprint $table) {
            $table->string('tahun_ajaran')->after('kode_jenjang');
            $table->foreign('tahun_ajaran')->references('kode')->on('tahun_ajaran')->restrictOnDelete();
            $table->index('tahun_ajaran');
        });

        Schema::table('jalur_pendaftaran', function (Blueprint $table) {
            $table->string('tahun_ajaran')->after('nama');
            $table->foreign('tahun_ajaran')->references('kode')->on('tahun_ajaran')->restrictOnDelete();
            $table->index('tahun_ajaran');
        });

        Schema::table('santri', function (Blueprint $table) {
            $table->string('tahun_ajaran')->nullable()->after('angkatan');
            $table->foreign('tahun_ajaran')->references('kode')->on('tahun_ajaran')->restrictOnDelete();
            $table->index('tahun_ajaran');
        });

        Schema::table('potongan_gelombang', function (Blueprint $table) {
            $table->foreign('tahun_ajaran')->references('kode')->on('tahun_ajaran')->restrictOnDelete();
        });

        Schema::table('target_santri', function (Blueprint $table) {
            $table->foreign('tahun_ajaran')->references('kode')->on('tahun_ajaran')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('target_santri', fn (Blueprint $t) => $t->dropForeign(['tahun_ajaran']));
        Schema::table('potongan_gelombang', fn (Blueprint $t) => $t->dropForeign(['tahun_ajaran']));
        Schema::table('santri', function (Blueprint $t) {
            $t->dropForeign(['tahun_ajaran']);
            $t->dropColumn('tahun_ajaran');
        });
        Schema::table('jalur_pendaftaran', function (Blueprint $t) {
            $t->dropForeign(['tahun_ajaran']);
            $t->dropColumn('tahun_ajaran');
        });
        Schema::table('jenis_biaya', function (Blueprint $t) {
            $t->dropForeign(['tahun_ajaran']);
            $t->dropColumn('tahun_ajaran');
        });
    }
};
