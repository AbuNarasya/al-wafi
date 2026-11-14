<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KALENDER LIBUR (keputusan user 14 Nov 2026): diisi admin, bisa berbeda per jenjang. Dipakai Portal Wali menu
 * Absensi: tanggal tanpa catatan absen pada hari libur tampil "Hari Libur — <nama>" (Sabtu–Minggu = libur sekolah
 * otomatis, tak perlu diisi). `kode_jenjang` NULL = semua jenjang. `cakupan`: sekolah (absen harian sekolah) ·
 * asrama (absen asrama, mis. santri pulang) · semua.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kalender_libur', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 150);
            $table->date('mulai');
            $table->date('selesai');
            $table->string('kode_jenjang', 20)->nullable();
            $table->foreign('kode_jenjang')->references('kode')->on('jenjang');
            $table->string('cakupan', 10)->default('semua');
            $table->string('keterangan', 500)->nullable();
            $table->unsignedBigInteger('dibuat_oleh')->nullable();
            $table->timestamps();
            $table->index(['mulai', 'selesai']);
        });
        DB::statement("ALTER TABLE kalender_libur ADD CONSTRAINT kalender_libur_cakupan_check CHECK (cakupan IN ('sekolah','asrama','semua'))");
        DB::statement('ALTER TABLE kalender_libur ADD CONSTRAINT kalender_libur_rentang_check CHECK (selesai >= mulai)');
    }

    public function down(): void
    {
        Schema::dropIfExists('kalender_libur');
    }
};
