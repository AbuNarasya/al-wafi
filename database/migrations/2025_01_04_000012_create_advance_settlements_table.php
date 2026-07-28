<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * advance_settlements — Penyelesaian Uang Muka (bisa memicu multi-entry jurnal:
 * jurnal penyelesaian + jurnal selisih kas). kode_rekening → bank_accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advance_settlements', function (Blueprint $table) {
            $table->increments('id');
            $table->date('tanggal');
            $table->string('kode_coa_uang_muka');
            $table->string('nama_coa_uang_muka');
            $table->decimal('nominal_uang_muka', 18, 2);
            $table->string('kode_coa_realisasi');
            $table->string('nama_coa_realisasi');
            $table->decimal('nominal_realisasi', 18, 2);
            $table->string('kode_rekening');
            $table->string('kode_unit')->nullable();
            $table->string('nomor_referensi')->nullable(); // PUM-YYYYMM-NNNNN
            $table->unsignedInteger('id_uang_muka')->nullable();
            $table->text('keterangan')->nullable();
            $table->enum('status', ['aktif', 'void'])->default('aktif');
            $table->unsignedInteger('id_pengguna');
            $table->timestamps();

            $table->foreign('kode_rekening')->references('kode_coa')->on('bank_accounts');
            $table->foreign('id_pengguna')->references('id_pengguna')->on('users');
            $table->index('kode_rekening');
            $table->index('id_uang_muka');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advance_settlements');
    }
};
