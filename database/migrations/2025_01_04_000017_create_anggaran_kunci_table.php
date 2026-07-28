<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * anggaran_kunci — Kunci anggaran per Tahun Anggaran. BARIS ADA = TA terkunci
 * (anggaran beku). Hanya admin yang mengunci/membuka (membuka = hapus baris).
 * PK = tahun (natural). Tanpa timestamps standar (punya locked_at sendiri).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anggaran_kunci', function (Blueprint $table) {
            $table->integer('tahun')->primary();
            $table->unsignedInteger('locked_by');
            $table->timestamp('locked_at')->useCurrent();
            $table->text('catatan')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anggaran_kunci');
    }
};
