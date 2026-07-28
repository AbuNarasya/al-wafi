<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * cash_out_details — Baris kas keluar. tipe: lainnya | invoice | inventory |
 * pengajuan. tipe=pengajuan mendebit akun Hutang Pengajuan (bukan beban lagi) —
 * mencegah biaya dobel (beban sudah diakui saat pengajuan disetujui).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_out_details', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('kode_transaksi');
            $table->string('tipe')->default('lainnya'); // lainnya | invoice | inventory | pengajuan
            $table->string('kode_coa');
            $table->string('nama_coa');
            $table->decimal('nominal', 18, 2);
            $table->text('keterangan')->nullable();
            $table->unsignedInteger('id_invoice')->nullable();
            $table->unsignedInteger('id_pengajuan')->nullable();
            $table->string('kode_persediaan')->nullable();
            $table->decimal('kuantiti', 18, 4)->nullable();
            $table->decimal('harga_satuan', 18, 2)->nullable();

            $table->foreign('kode_transaksi')->references('kode_transaksi')->on('cash_out')->cascadeOnDelete();
            $table->index('kode_transaksi');
            $table->index('kode_coa');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_out_details');
    }
};
