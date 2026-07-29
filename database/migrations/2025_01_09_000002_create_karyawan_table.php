<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * karyawan — master RINGKAS pegawai.
 *
 * Sengaja seadanya: yang dibutuhkan sekarang hanya "siapa yang berhutang" pada
 * modul Pinjaman Karyawan. Data kepegawaian yang sesungguhnya (kontrak, gaji,
 * kehadiran) menjadi milik HRD nanti — saat itu tabel ini cukup ditambahi satu
 * kolom penghubung, tanpa membongkar pinjamannya.
 *
 * `users` TIDAK dipakai sebagai penggantinya: itu akun login, dan tidak semua
 * karyawan punya akun. Hubungannya dibuat opsional lewat id_pengguna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('karyawan', function (Blueprint $table) {
            $table->string('kode')->primary(); // NIK / nomor pegawai
            $table->string('nama');
            $table->string('jabatan')->nullable();
            $table->string('kode_bagian')->nullable();
            $table->unsignedInteger('id_pengguna')->nullable(); // akun login, bila ada
            $table->enum('status', ['aktif', 'nonaktif'])->default('aktif');
            $table->text('keterangan')->nullable();
            $table->timestamps();

            $table->foreign('kode_bagian')->references('kode_bagian')->on('bagian')->nullOnDelete();
            $table->foreign('id_pengguna')->references('id_pengguna')->on('users')->nullOnDelete();
            $table->index('nama');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('karyawan');
    }
};
