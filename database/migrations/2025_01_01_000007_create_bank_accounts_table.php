<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * bank_accounts — Rekening kas/bank. PK = FK ke coa_detail (satu akun kas/bank
 * = satu COA). jenis_rekening: tunai | bank.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->string('kode_coa')->primary();
            $table->string('nama_rekening');
            $table->enum('jenis_rekening', ['tunai', 'bank'])->default('bank');
            $table->string('nama_bank')->nullable();
            $table->string('no_rekening')->nullable();
            $table->enum('status', ['aktif', 'nonaktif'])->default('aktif');
            $table->timestamps();

            $table->foreign('kode_coa')->references('kode_coa')->on('coa_detail');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
