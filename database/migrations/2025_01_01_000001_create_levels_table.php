<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * levels — Level otorisasi KEUANGAN (batas nominal transaksi per pengguna).
 * PK natural: kode_level (string). max_transaksi null = tidak terbatas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('levels', function (Blueprint $table) {
            $table->string('kode_level')->primary();
            $table->string('nama_level');
            $table->decimal('max_transaksi', 18, 2)->nullable();
            $table->text('keterangan')->nullable();
            $table->enum('status', ['aktif', 'nonaktif'])->default('aktif');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('levels');
    }
};
