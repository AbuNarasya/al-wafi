<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reset kata sandi staf lewat email (keputusan user 12 Nov 2026).
 *
 *  - `users.email`: tujuan tautan reset. Boleh kosong (diisi admin / staf sendiri); satu alamat hanya untuk satu
 *    akun — unik tanpa membedakan huruf besar, NULL tak ikut (indeks parsial).
 *  - `users.harus_ganti_sandi`: sandi buatan admin (akun baru / Ubah Pengguna) hanya sementara — staf wajib
 *    membuat sandinya sendiri di Profil sebelum membuka layar lain (middleware WajibGantiSandi).
 *  - `reset_sandi_staf`: tautan sekali pakai. Yang disimpan HASH SHA-256 tokennya, bukan tokennya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('email', 255)->nullable();
            $t->boolean('harus_ganti_sandi')->default(false);
        });
        DB::statement('CREATE UNIQUE INDEX users_email_unik ON users (lower(email)) WHERE email IS NOT NULL');

        Schema::create('reset_sandi_staf', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('id_pengguna');
            $t->foreign('id_pengguna')->references('id_pengguna')->on('users')->cascadeOnDelete();
            $t->string('token_hash', 64)->unique();
            $t->timestamp('kedaluwarsa_pada');
            $t->timestamp('dipakai_pada')->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamps();
            $t->index(['id_pengguna', 'dipakai_pada']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reset_sandi_staf');
        DB::statement('DROP INDEX IF EXISTS users_email_unik');
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['email', 'harus_ganti_sandi']);
        });
    }
};
