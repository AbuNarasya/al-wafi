<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * rekening_tersimpan — buku rekening tujuan MILIK MASING-MASING PEMOHON.
 *
 * Dipanggil kembali saat menyusun pengajuan berikutnya, supaya rekening penerima
 * yang sama tak diketik ulang (dan tak salah ketik) tiap kali. Sengaja per
 * pengguna: kekeliruan satu orang tidak ikut terpakai oleh pengajuan orang lain.
 *
 * Ini BUKAN sumber nilai yang tercetak di dokumen — pengajuan menyimpan
 * salinannya sendiri, jadi menyunting/menghapus baris di sini tak pernah
 * mengubah dokumen yang sudah terbit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rekening_tersimpan', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_pengguna');
            $table->string('bank');
            $table->string('no_rekening');
            $table->string('atas_nama');
            $table->timestamps();

            $table->foreign('id_pengguna')->references('id_pengguna')->on('users')->cascadeOnDelete();
            // Satu pemohon tak perlu dua baris identik; menyimpan rekening yang
            // sama berulang kali hanya memanjangkan daftarnya tanpa guna.
            $table->unique(['id_pengguna', 'bank', 'no_rekening'], 'rekening_tersimpan_unik');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rekening_tersimpan');
    }
};
