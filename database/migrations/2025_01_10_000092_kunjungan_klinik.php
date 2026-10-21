<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KLINIK — catatan kunjungan santri (keputusan user 21 Okt 2026): catatan lengkap (tanda vital, pemeriksaan,
 * tindakan, obat), wali dikabari tiap kunjungan (kategori push "kesehatan"), obat dicatat tanpa stok, dan
 * petugas boleh menerbitkan TAGIHAN LAIN-LAIN ke wali dari sebuah kunjungan (`id_tagihan`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kunjungan_klinik', function (Blueprint $table) {
            $table->increments('id');
            $table->string('nomor', 30)->unique();
            $table->unsignedInteger('id_santri');
            $table->timestamp('datang_pada');
            $table->text('keluhan');
            $table->decimal('suhu', 4, 1)->nullable();
            $table->string('tensi', 15)->nullable();
            $table->unsignedSmallInteger('nadi')->nullable();
            $table->decimal('berat', 5, 1)->nullable();
            $table->text('pemeriksaan')->nullable();
            $table->text('tindakan')->nullable();
            $table->enum('tindak_lanjut', ['kembali_kelas', 'istirahat_asrama', 'dirawat', 'dirujuk', 'dijemput']);
            $table->string('rujukan_ke')->nullable();
            $table->enum('status', ['dirawat', 'selesai']);
            $table->timestamp('selesai_pada')->nullable();
            $table->text('catatan_selesai')->nullable();
            $table->unsignedInteger('id_tagihan')->nullable();
            $table->unsignedInteger('dicatat_oleh')->nullable();
            $table->unsignedInteger('diselesaikan_oleh')->nullable();
            $table->timestamps();

            $table->foreign('id_santri')->references('id')->on('santri')->cascadeOnDelete();
            $table->foreign('id_tagihan')->references('id')->on('tagihan_santri')->nullOnDelete();
            $table->foreign('dicatat_oleh')->references('id_pengguna')->on('users')->nullOnDelete();
            $table->foreign('diselesaikan_oleh')->references('id_pengguna')->on('users')->nullOnDelete();
            $table->index(['datang_pada']);
            $table->index(['id_santri', 'datang_pada']);
        });

        Schema::create('obat_kunjungan_klinik', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_kunjungan');
            $table->string('nama');
            $table->string('dosis')->nullable();
            $table->string('jumlah', 50)->nullable();

            $table->foreign('id_kunjungan')->references('id')->on('kunjungan_klinik')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('obat_kunjungan_klinik');
        Schema::dropIfExists('kunjungan_klinik');
    }
};
