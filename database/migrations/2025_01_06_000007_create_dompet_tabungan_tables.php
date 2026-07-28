<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dompet & tabungan (akad WADI'AH/titipan — LIABILITAS, tanpa bunga).
 * dompet_wali (keluarga), dompet_santri (jajan, tak bisa topup tunai),
 * tabungan_santri. saldo = akumulasi; mutasi_dompet = buku besarnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dompet_wali', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_wali')->unique();
            $table->decimal('saldo', 18, 2)->default(0);
            $table->string('va_number')->nullable();
            $table->timestamps();
            $table->foreign('id_wali')->references('id')->on('wali')->restrictOnDelete();
        });

        Schema::create('dompet_santri', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_santri')->unique();
            $table->decimal('saldo', 18, 2)->default(0);
            $table->boolean('kunci_tarik')->default(false);
            $table->timestamps();
            $table->foreign('id_santri')->references('id')->on('santri')->restrictOnDelete();
        });

        Schema::create('tabungan_santri', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_santri')->unique();
            $table->decimal('saldo', 18, 2)->default(0);
            $table->timestamps();
            $table->foreign('id_santri')->references('id')->on('santri')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tabungan_santri');
        Schema::dropIfExists('dompet_santri');
        Schema::dropIfExists('dompet_wali');
    }
};
