<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * termin_uang_pangkal — Baris jadwal angsuran. Status (tertutup/belum) TIDAK
 * disimpan — diturunkan FIFO dari TagihanSantri.sisa. diingatkan_* = jejak tagih manual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('termin_uang_pangkal', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_rencana');
            $table->integer('urutan');
            $table->decimal('nominal', 18, 2);
            $table->date('jatuh_tempo');
            $table->text('keterangan')->nullable();
            $table->date('diingatkan_pada')->nullable();
            $table->unsignedInteger('diingatkan_oleh')->nullable();
            $table->text('catatan_reminder')->nullable();
            $table->text('feedback')->nullable();
            $table->timestamps();

            $table->foreign('id_rencana')->references('id')->on('rencana_angsuran_uang_pangkal')->cascadeOnDelete();
            $table->foreign('diingatkan_oleh')->references('id_pengguna')->on('users')->nullOnDelete();
            $table->unique(['id_rencana', 'urutan']);
            $table->index('id_rencana');
            $table->index('jatuh_tempo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('termin_uang_pangkal');
    }
};
