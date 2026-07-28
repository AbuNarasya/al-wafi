<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * cash_in_details — Baris kas masuk. jenis_kas_masuk: uang_muka | pelunasan |
 * pendapatan | lain. status_pengakuan: belum_diakui | sudah_diakui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_in_details', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('kode_transaksi');
            $table->string('kode_coa');
            $table->string('nama_coa');
            $table->enum('jenis_kas_masuk', ['uang_muka', 'pelunasan', 'pendapatan', 'lain'])->default('pendapatan');
            $table->decimal('nominal', 18, 2);
            $table->text('keterangan')->nullable();
            $table->enum('status_pengakuan', ['belum_diakui', 'sudah_diakui'])->default('sudah_diakui');
            $table->string('kode_persediaan')->nullable();
            $table->decimal('kuantiti', 18, 4)->nullable();
            $table->decimal('harga_satuan', 18, 2)->nullable();

            $table->foreign('kode_transaksi')->references('kode_transaksi')->on('cash_in')->cascadeOnDelete();
            $table->index('kode_transaksi');
            $table->index('kode_coa');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_in_details');
    }
};
