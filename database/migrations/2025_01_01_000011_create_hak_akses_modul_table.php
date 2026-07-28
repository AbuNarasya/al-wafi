<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * hak_akses_modul — Hak akses per pengguna per modul. DENY BY DEFAULT: tidak ada
 * baris = tidak punya akses. PK komposit (id_pengguna, kode_modul).
 * kode_modul merujuk registri di kode (tanpa FK, sengaja). `menu` = tampil di
 * sidebar (sumbu terpisah dari `lihat`). `hapus` termasuk void/pembatalan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hak_akses_modul', function (Blueprint $table) {
            $table->unsignedInteger('id_pengguna');
            $table->string('kode_modul');
            $table->boolean('lihat')->default(false);
            $table->boolean('buat')->default(false);
            $table->boolean('ubah')->default(false);
            $table->boolean('hapus')->default(false);
            $table->boolean('menu')->default(false);

            $table->primary(['id_pengguna', 'kode_modul']);
            $table->foreign('id_pengguna')->references('id_pengguna')->on('users')->cascadeOnDelete();
            $table->index('id_pengguna');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hak_akses_modul');
    }
};
