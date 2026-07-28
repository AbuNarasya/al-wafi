<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * potongan_gelombang — Master potongan uang pangkal per gelombang/jenjang/tahun
 * ajaran. Nominal tetap, tidak ditimpa (baris baru; lama aktif=false = arsip).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('potongan_gelombang', function (Blueprint $table) {
            $table->increments('id');
            $table->string('tahun_ajaran'); // "2026/2027"
            $table->integer('gelombang');
            $table->string('kode_jenjang')->nullable();
            $table->decimal('potongan', 18, 2);
            $table->integer('masa_berlaku_hari')->default(7);
            $table->boolean('aktif')->default(true);
            $table->text('keterangan')->nullable();
            $table->timestamps();

            $table->unique(['tahun_ajaran', 'gelombang', 'kode_jenjang']);
            $table->index(['aktif', 'gelombang', 'kode_jenjang']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('potongan_gelombang');
    }
};
