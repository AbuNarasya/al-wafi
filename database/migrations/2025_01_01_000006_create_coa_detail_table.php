<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * coa_detail — Akun detail (level 4). jenis_saldo menentukan sisi normal akun
 * (debet/kredit). Jalur integritas laporan: dirujuk journal_lines,
 * opening_balances, bank_accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coa_detail', function (Blueprint $table) {
            $table->string('kode_coa')->primary();
            $table->string('nama_coa');
            $table->string('kode_grup');
            $table->enum('jenis_saldo', ['debet', 'kredit']);
            $table->enum('status', ['aktif', 'nonaktif'])->default('aktif');
            $table->text('keterangan')->nullable();
            $table->timestamps();

            $table->foreign('kode_grup')->references('kode_grup')->on('coa_groups');
            $table->index('kode_grup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coa_detail');
    }
};
