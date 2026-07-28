<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * users — Pengguna aplikasi. Empat sumbu wewenang yang saling melengkapi:
 *   kode_level          -> batas nominal transaksi keuangan (FK levels)
 *   peringkat_pengajuan -> pangkat rantai Pengajuan Pembayaran (FK level_pengajuan)
 *   tim_keuangan        -> boleh menjalankan tahap fungsi (verifikasi dokumen)
 *   hak_akses_modul     -> lihat/buat/ubah/hapus per modul (tabel terpisah)
 * is_admin melewati pemeriksaan hak akses modul, TIDAK melewati rantai persetujuan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id_pengguna');
            $table->string('username')->unique();
            $table->string('nama');
            $table->string('jabatan')->nullable();
            $table->string('password_hash');
            $table->string('kode_level');
            $table->boolean('is_admin')->default(false);
            $table->string('kode_bagian')->nullable();
            $table->integer('peringkat_pengajuan')->nullable();
            $table->boolean('tim_keuangan')->default(false);
            $table->enum('status', ['aktif', 'nonaktif'])->default('aktif');
            $table->timestamps();

            $table->foreign('kode_level')->references('kode_level')->on('levels');
            $table->foreign('kode_bagian')->references('kode_bagian')->on('bagian');
            $table->foreign('peringkat_pengajuan')->references('peringkat')->on('level_pengajuan');
            $table->index('kode_level');
            $table->index('kode_bagian');
            $table->index('peringkat_pengajuan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
