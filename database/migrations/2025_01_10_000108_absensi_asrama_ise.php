<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ABSENSI ASRAMA DARI ISE (keputusan user 4 Nov 2026, pilihan B = ISE push ke ERP).
 *
 *  - absensi_asrama: salinan catatan absen asrama kiriman ISE. `ise_id` unik → kiriman ulang/koreksi menimpa, tak
 *    menggandakan. Santri dicocokkan lewat id santri ERP lalu NIS (kelak ISE memakai data santri ERP); tak cocok =
 *    id_santri NULL, tetap disimpan & tampil di layar staf. `catatan` & `pencatat` internal — TAK ditampilkan ke wali.
 *    `kategori` = terjemahan status ISE (hadir/terlambat/tidak_hadir/izin/uzur/lainnya) untuk ringkasan & kabar.
 *  - log_kiriman_ise: satu baris per kiriman (jumlah diterima/ditolak) — untuk memeriksa integrasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('absensi_asrama', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('ise_id', 100)->unique();
            $table->unsignedInteger('id_santri')->nullable();
            $table->string('ise_santri_id', 100)->nullable();
            $table->string('nis', 50)->nullable();
            $table->string('nama_santri')->nullable();
            $table->timestamp('waktu');
            $table->string('asrama', 100)->nullable();
            $table->string('sesi', 150);
            $table->string('status', 100);
            $table->string('kategori', 20);
            $table->text('catatan')->nullable();
            $table->string('pencatat')->nullable();
            $table->timestamp('dikabarkan_pada')->nullable();
            $table->timestamps();

            $table->foreign('id_santri')->references('id')->on('santri')->nullOnDelete();
            $table->index(['id_santri', 'waktu']);
            $table->index(['waktu']);
        });

        Schema::create('log_kiriman_ise', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('jenis', 50);
            $table->unsignedInteger('jumlah');
            $table->unsignedInteger('disimpan');
            $table->unsignedInteger('dihapus');
            $table->unsignedInteger('ditolak');
            $table->unsignedInteger('tak_tercocok');
            $table->text('galat')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['jenis', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_kiriman_ise');
        Schema::dropIfExists('absensi_asrama');
    }
};
