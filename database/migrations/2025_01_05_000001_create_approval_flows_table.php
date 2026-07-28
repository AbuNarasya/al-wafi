<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * approval_flows — Definisi rantai persetujuan per jenis dokumen (Budget,
 * PengajuanPembayaran, ...). Satu mesin melayani banyak jenis dokumen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_flows', function (Blueprint $table) {
            $table->string('kode_flow')->primary();
            $table->string('nama_flow');
            $table->string('jenis_dokumen');
            $table->enum('status', ['aktif', 'nonaktif'])->default('aktif');
            $table->timestamps();

            $table->index('jenis_dokumen');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_flows');
    }
};
