<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * rencana_angsuran_uang_pangkal — Kesepakatan angsuran (ber-versi). Murni jadwal,
 * TIDAK berjurnal. Re-negosiasi: versi lama digantikan, baru aktif (Σ termin tetap).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rencana_angsuran_uang_pangkal', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_tagihan');
            $table->integer('versi')->default(1);
            $table->enum('status', ['aktif', 'digantikan'])->default('aktif');
            $table->date('disepakati_pada');
            $table->unsignedInteger('disepakati_oleh');
            $table->text('alasan')->nullable();
            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->foreign('id_tagihan')->references('id')->on('tagihan_santri')->cascadeOnDelete();
            $table->foreign('disepakati_oleh')->references('id_pengguna')->on('users')->restrictOnDelete();
            $table->unique(['id_tagihan', 'versi']);
            $table->index(['id_tagihan', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rencana_angsuran_uang_pangkal');
    }
};
