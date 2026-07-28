<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * journal_lines — BARIS jurnal. debet/kredit Decimal(18,2). Dimensi kode_bagian
 * (realisasi anggaran) & kode_unit (Laba Rugi/Arus Kas per unit) melekat di
 * baris — onDelete RESTRICT agar angka laporan historis tidak bergeser diam-diam.
 * kode_persediaan/kuantiti opsional (baris penggerak stok). Tanpa timestamps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_lines', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('entry_id');
            $table->string('kode_coa');
            $table->string('nama_coa')->nullable();
            $table->decimal('debet', 18, 2)->default(0);
            $table->decimal('kredit', 18, 2)->default(0);
            $table->text('keterangan')->nullable();
            $table->string('kode_persediaan')->nullable();
            $table->decimal('kuantiti', 18, 4)->nullable();
            $table->string('kode_bagian')->nullable();
            $table->string('kode_unit')->nullable();

            $table->foreign('entry_id')->references('id')->on('journal_entries')->cascadeOnDelete();
            $table->foreign('kode_coa')->references('kode_coa')->on('coa_detail');
            $table->foreign('kode_unit')->references('kode_unit')->on('business_units')->restrictOnDelete();
            $table->foreign('kode_bagian')->references('kode_bagian')->on('bagian')->restrictOnDelete();
            $table->index('entry_id');
            $table->index('kode_coa');
            $table->index('kode_bagian');
            $table->index('kode_unit');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
    }
};
