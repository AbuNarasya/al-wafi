<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * opening_balances — Saldo awal per akun. posted=true → sudah difinalisasi jadi
 * jurnal pembuka (baris terkunci). journal_entry_id = jurnal pembuka yang
 * dihasilkan (indeks saja, tanpa FK — sengaja, agar bisa di-void saat revisi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_balances', function (Blueprint $table) {
            $table->increments('id');
            $table->string('kode_coa');
            $table->enum('jenis_saldo', ['debet', 'kredit']);
            $table->decimal('saldo', 18, 2);
            $table->boolean('posted')->default(false);
            $table->unsignedInteger('journal_entry_id')->nullable();
            $table->timestamps();

            $table->foreign('kode_coa')->references('kode_coa')->on('coa_detail');
            $table->index('kode_coa');
            $table->index('journal_entry_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_balances');
    }
};
