<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * budgets — Anggaran per akun COA, per bulan, per unit (kode_unit null = semua
 * unit). Realisasi dihitung dari journal_lines (tidak disimpan). kode_bagian
 * WAJIB (pemilik anggaran) — FK Restrict.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('tahun');
            $table->integer('bulan'); // 1..12
            $table->string('kode_coa');
            $table->string('kode_bagian');
            $table->string('kode_unit')->nullable();
            $table->decimal('nominal', 18, 2);
            $table->text('keterangan')->nullable();
            $table->timestamps();

            $table->foreign('kode_bagian')->references('kode_bagian')->on('bagian')->restrictOnDelete();
            $table->index('tahun');
            $table->index('kode_coa');
            $table->index('kode_bagian');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};
