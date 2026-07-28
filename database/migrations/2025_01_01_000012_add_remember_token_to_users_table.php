<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom remember_token untuk fitur "Ingat saya" (persistent login) Laravel.
 * Tidak ada di skema Prisma asli (auth Express berbasis sesi); ditambahkan
 * karena login Laravel memakai token remember-me pada tabel users.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->rememberToken();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('remember_token');
        });
    }
};
