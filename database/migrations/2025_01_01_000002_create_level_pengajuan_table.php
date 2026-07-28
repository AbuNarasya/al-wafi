<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * level_pengajuan — Peringkat pengguna pada rantai persetujuan Pengajuan
 * Pembayaran. PK = peringkat (Int, natural, BUKAN autoincrement). 1 = tertinggi.
 * Tabel (bukan enum) karena label `nama` bisa diubah admin lewat UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('level_pengajuan', function (Blueprint $table) {
            $table->integer('peringkat')->primary();
            $table->string('nama');
            $table->text('keterangan')->nullable();
            $table->enum('status', ['aktif', 'nonaktif'])->default('aktif');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('level_pengajuan');
    }
};
