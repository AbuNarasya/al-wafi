<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * customers — Master customer. kode_coa_pendapatan/piutang = field pemilih akun
 * (string ber-validasi service, tanpa FK — sesuai konvensi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->string('kode_customer')->primary();
            $table->string('nama_customer');
            $table->string('kode_jenis_customer');
            $table->string('kode_coa_pendapatan')->nullable();
            $table->string('kode_coa_piutang')->nullable();
            $table->text('alamat')->nullable();
            $table->string('telepon')->nullable();
            $table->string('email')->nullable();
            $table->enum('status', ['aktif', 'nonaktif'])->default('aktif');
            $table->timestamps();

            $table->foreign('kode_jenis_customer')->references('kode_jenis_customer')->on('customer_types');
            $table->index('kode_jenis_customer');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
