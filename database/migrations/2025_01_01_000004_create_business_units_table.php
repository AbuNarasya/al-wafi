<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * business_units — Unit bisnis (dimensi 1 voucher = 1 unit pada level entry
 * jurnal). Berbeda dengan `bagian` yang berlaku per baris.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_units', function (Blueprint $table) {
            $table->string('kode_unit')->primary();
            $table->string('nama_unit');
            $table->enum('status', ['aktif', 'nonaktif'])->default('aktif');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_units');
    }
};
