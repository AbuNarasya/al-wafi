<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UJIAN SELEKSI PSB — kartu peserta & absensi QR (keputusan user 21 Okt 2026).
 *
 *  • `jadwal_ujian` — per T.A, boleh dipersempit ke jenjang dan/atau gelombang (kosong = semua).
 *    Tanggal seorang peserta: santri.tanggal_ujian → jenjang+gelombang → gelombang → jenjang → T.A saja.
 *  • `santri.tanggal_ujian` — tanggal khusus satu peserta (diisi staf), mengalahkan jadwal.
 *  • `kehadiran_ujian` — dicatat saat panitia memindai QR kartu; sekali per santri per T.A.
 *  • `company_settings.kupon_makan_orang` & `perhatian_kartu_ujian` — isi kartu yang bisa diatur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jadwal_ujian', function (Blueprint $table) {
            $table->increments('id');
            $table->string('tahun_ajaran');
            $table->string('kode_jenjang')->nullable();
            $table->string('kode_gelombang', 50)->nullable();
            $table->date('tanggal');
            $table->string('jam', 50)->nullable();
            $table->string('tempat')->nullable();
            $table->string('keterangan')->nullable();
            $table->timestamps();

            $table->foreign('tahun_ajaran')->references('kode')->on('tahun_ajaran');
            $table->foreign('kode_jenjang')->references('kode')->on('jenjang');
        });

        Schema::table('santri', function (Blueprint $table) {
            $table->date('tanggal_ujian')->nullable();
        });

        Schema::create('kehadiran_ujian', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_santri');
            $table->string('tahun_ajaran');
            $table->timestamp('hadir_pada');
            $table->unsignedInteger('dicatat_oleh')->nullable();
            $table->timestamps();

            $table->unique(['id_santri', 'tahun_ajaran']);
            $table->foreign('id_santri')->references('id')->on('santri')->cascadeOnDelete();
            $table->foreign('dicatat_oleh')->references('id_pengguna')->on('users')->nullOnDelete();
        });

        Schema::table('company_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('kupon_makan_orang')->default(2);
            $table->text('perhatian_kartu_ujian')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', fn (Blueprint $t) => $t->dropColumn(['kupon_makan_orang', 'perhatian_kartu_ujian']));
        Schema::dropIfExists('kehadiran_ujian');
        Schema::table('santri', fn (Blueprint $t) => $t->dropColumn('tanggal_ujian'));
        Schema::dropIfExists('jadwal_ujian');
    }
};
