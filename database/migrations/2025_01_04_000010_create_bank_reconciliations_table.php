<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * bank_reconciliations — Rekonsiliasi bank (buku vs rekening koran).
 * saldo_buku = snapshot saldo GL akun bank s/d tanggal. status: draft | selesai.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->increments('id');
            $table->string('kode_coa'); // akun bank
            $table->date('tanggal');
            $table->decimal('saldo_bank', 18, 2);
            $table->decimal('saldo_buku', 18, 2);
            $table->string('status')->default('draft'); // draft | selesai
            $table->text('keterangan')->nullable();
            $table->unsignedInteger('id_pengguna')->nullable();
            $table->timestamps();

            $table->index('kode_coa');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliations');
    }
};
