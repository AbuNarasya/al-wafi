<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * budget_pengajuan — Usulan anggaran satu scope (tahun + bagian + unit) yang
 * menunggu persetujuan berjenjang sebelum ditulis ke `budgets`. Snapshot penuh:
 * saat diterapkan MENGGANTI scope. status: diajukan | disetujui | ditolak | dibatalkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_pengajuan', function (Blueprint $table) {
            $table->increments('id');
            $table->string('nomor')->unique();
            $table->integer('tahun'); // label Tahun Anggaran
            $table->integer('bulan_awal')->default(1);
            $table->string('kode_bagian');
            $table->string('kode_unit')->nullable();
            $table->string('status')->default('diajukan');
            $table->decimal('nominal', 18, 2);
            $table->text('keterangan')->nullable();
            $table->unsignedInteger('id_pengguna');
            $table->timestamps();

            $table->foreign('kode_bagian')->references('kode_bagian')->on('bagian')->restrictOnDelete();
            $table->index('status');
            $table->index(['tahun', 'kode_bagian']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_pengajuan');
    }
};
