<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * asset_movements — Penambahan nilai perolehan ke aset eksisting dari transaksi
 * (Invoice/KasKeluar). Disimpan agar bisa dibalik saat transaksi di-void.
 * Hanya punya created_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_movements', function (Blueprint $table) {
            $table->increments('id');
            $table->string('kode_aset');
            $table->string('sumber_ref'); // nomor transaksi sumber
            $table->string('sumber_modul'); // Invoice | KasKeluar
            $table->decimal('nominal', 18, 2);
            $table->decimal('kuantiti', 18, 4)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('kode_aset')->references('kode_aset')->on('assets')->cascadeOnDelete();
            $table->index('kode_aset');
            $table->index('sumber_ref');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_movements');
    }
};
