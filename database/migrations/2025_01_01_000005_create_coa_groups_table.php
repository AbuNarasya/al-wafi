<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * coa_groups — Hierarki kelompok akun (Chart of Accounts) via kode_induk.
 * level: 1=kelompok utama (1-5), 2=grup parent, 3=grup akun. Akun detail
 * (coa_detail) = level 4 di bawah level 3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coa_groups', function (Blueprint $table) {
            $table->string('kode_grup')->primary();
            $table->string('nama_grup');
            $table->string('kode_induk')->nullable();
            $table->integer('level')->default(1);
            $table->timestamps();

            $table->index('kode_induk');
        });

        // FK self-reference ditambahkan terpisah agar PK coa_groups sudah terbentuk.
        Schema::table('coa_groups', function (Blueprint $table) {
            $table->foreign('kode_induk')->references('kode_grup')->on('coa_groups');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coa_groups');
    }
};
