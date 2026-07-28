<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Infrastruktur Laravel: tabel `sessions` untuk SESSION_DRIVER=database.
 *
 * Tabel `users` aplikasi (PK id_pengguna, password_hash, dll) TIDAK dibuat di
 * sini — ia punya dependensi FK ke `levels` & `bagian`, sehingga dibuat di
 * migration tersendiri setelah tabel-tabel induknya (lihat create_users_table
 * di grup fondasi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            // Menyimpan id_pengguna user yang login (tanpa FK, sesuai default Laravel).
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
