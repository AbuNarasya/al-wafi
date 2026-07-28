<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * inventory — Master persediaan. Stok saat ini = stok_masuk - stok_keluar.
 * kode_coa = COA persediaan (field pemilih akun, tanpa FK).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory', function (Blueprint $table) {
            $table->string('kode_persediaan')->primary();
            $table->string('nama_persediaan');
            $table->string('satuan')->nullable();
            $table->decimal('harga_perolehan', 18, 2)->default(0);
            $table->decimal('stok_masuk', 18, 4)->default(0);
            $table->decimal('stok_keluar', 18, 4)->default(0);
            $table->string('kode_coa')->nullable();
            $table->enum('status', ['aktif', 'nonaktif'])->default('aktif');
            $table->timestamps();

            $table->index('kode_coa');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory');
    }
};
