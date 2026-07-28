<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tagihan_santri — SUBLEDGER tagihan per santri. sisa disimpan (diubah hanya
 * lewat verifikasi pembayaran). sudah_akrual = penentu sisi kredit saat bayar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tagihan_santri', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_santri');
            $table->string('kode_jenis');
            $table->string('periode')->nullable(); // SPP: "2026-07"
            $table->decimal('nominal', 18, 2);
            $table->decimal('sisa', 18, 2);
            $table->enum('status', ['belum_bayar', 'sebagian', 'lunas', 'batal'])->default('belum_bayar');
            $table->boolean('sudah_akrual')->default(false);
            $table->date('jatuh_tempo')->nullable();
            $table->text('keterangan')->nullable();
            $table->timestamps();

            $table->foreign('id_santri')->references('id')->on('santri')->cascadeOnDelete();
            $table->foreign('kode_jenis')->references('kode')->on('jenis_biaya')->restrictOnDelete();
            $table->unique(['id_santri', 'kode_jenis', 'periode']);
            $table->index(['id_santri', 'status']);
            $table->index(['kode_jenis', 'periode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tagihan_santri');
    }
};
