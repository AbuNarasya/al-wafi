<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * bank_reconciliation_items — Baris jurnal akun bank yang direkonsiliasi.
 * cleared = muncul di koran. is_adjustment = jurnal koreksi (biaya admin/bunga).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_reconciliation_items', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_rekonsiliasi');
            $table->unsignedInteger('journal_line_id');
            $table->unsignedInteger('entry_id');
            $table->date('tanggal');
            $table->text('keterangan')->nullable();
            $table->decimal('debet', 18, 2);
            $table->decimal('kredit', 18, 2);
            $table->boolean('cleared')->default(false);
            $table->boolean('is_adjustment')->default(false);

            $table->foreign('id_rekonsiliasi')->references('id')->on('bank_reconciliations')->cascadeOnDelete();
            $table->index('id_rekonsiliasi');
            $table->index('journal_line_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliation_items');
    }
};
