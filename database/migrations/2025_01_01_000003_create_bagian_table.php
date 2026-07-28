<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * bagian — Struktur organisasi (Yayasan → Bidang → Bagian), hierarki via
 * kode_induk (self-reference). level: 1=yayasan, 2=bidang, 3=bagian.
 * Dimensi ANGGARAN: dipakai di journal_lines & budgets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bagian', function (Blueprint $table) {
            $table->string('kode_bagian')->primary();
            $table->string('nama_bagian');
            $table->string('kode_induk')->nullable();
            $table->integer('level')->default(1);
            $table->enum('status', ['aktif', 'nonaktif'])->default('aktif');
            $table->text('keterangan')->nullable();
            $table->timestamps();

            $table->index('kode_induk');
        });

        // FK self-reference ditambahkan terpisah agar PK bagian sudah terbentuk.
        Schema::table('bagian', function (Blueprint $table) {
            $table->foreign('kode_induk')->references('kode_bagian')->on('bagian');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bagian');
    }
};
