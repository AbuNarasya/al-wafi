<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** vendors — Master vendor. metode_pembayaran: tunai | termin (+ rekening bank). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->string('kode_vendor')->primary();
            $table->string('nama_vendor');
            $table->string('kode_jenis_vendor');
            $table->text('alamat')->nullable();
            $table->string('telepon')->nullable();
            $table->string('metode_pembayaran')->default('tunai');
            $table->integer('termin_hari')->nullable();
            $table->string('no_rekening')->nullable();
            $table->string('bank')->nullable();
            $table->string('atas_nama')->nullable();
            $table->enum('status', ['aktif', 'nonaktif'])->default('aktif');
            $table->timestamps();

            $table->foreign('kode_jenis_vendor')->references('kode_jenis_vendor')->on('vendor_types');
            $table->index('kode_jenis_vendor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
