<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * pengajuan_pembayaran_detail — Baris pengajuan (bisa beberapa akun). kode_unit
 * PER BARIS (wajib) — inilah yang membuat satu pengajuan melayani beberapa unit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengajuan_pembayaran_detail', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_pengajuan');
            $table->string('kode_coa');
            $table->string('nama_coa');
            $table->decimal('nominal', 18, 2);
            $table->string('kode_unit');
            $table->text('keterangan')->nullable();

            $table->foreign('id_pengajuan')->references('id')->on('pengajuan_pembayaran')->cascadeOnDelete();
            $table->foreign('kode_unit')->references('kode_unit')->on('business_units')->restrictOnDelete();
            $table->index('id_pengajuan');
            $table->index('kode_coa');
            $table->index('kode_unit');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengajuan_pembayaran_detail');
    }
};
