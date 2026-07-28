<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * jenis_biaya — Master jenis biaya kesantrian. tipe: registrasi | uang_pangkal
 * | spp | lain. kode_coa_piutang null = CASH BASIS (registrasi). kode_unit WAJIB.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jenis_biaya', function (Blueprint $table) {
            $table->string('kode')->primary();
            $table->string('nama');
            $table->enum('tipe', ['registrasi', 'uang_pangkal', 'spp', 'lain']);
            $table->decimal('nominal', 18, 2)->nullable();
            $table->string('kode_jenjang')->nullable();
            $table->string('kode_coa_pendapatan');
            $table->string('kode_coa_piutang')->nullable();
            $table->string('kode_coa_diterima_dimuka')->nullable();
            $table->string('kode_unit');
            $table->boolean('berulang')->default(false); // SPP = true
            $table->enum('status', ['aktif', 'nonaktif'])->default('aktif');
            $table->timestamps();

            $table->foreign('kode_unit')->references('kode_unit')->on('business_units')->restrictOnDelete();
            $table->index('tipe');
            $table->index('kode_unit');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jenis_biaya');
    }
};
