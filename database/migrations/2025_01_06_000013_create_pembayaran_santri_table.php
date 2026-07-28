<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * pembayaran_santri — Satu setoran. Dicatat PPSB, DIVERIFIKASI Keuangan
 * (pemisahan dua tangan). journal_entry_id & buku besar hanya tersentuh setelah verifikasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pembayaran_santri', function (Blueprint $table) {
            $table->increments('id');
            $table->string('nomor')->unique();
            $table->unsignedInteger('id_santri');
            $table->unsignedInteger('id_tagihan')->nullable();
            $table->date('tanggal');
            $table->decimal('nominal', 18, 2);
            $table->string('kode_rekening');
            $table->string('sumber')->default('manual'); // manual | xendit | dompet_wali
            $table->string('metode')->nullable();
            $table->string('external_id')->nullable()->unique();
            $table->string('provider_ref')->nullable()->unique();
            $table->string('bukti_path')->nullable();
            $table->text('catatan')->nullable();
            $table->enum('status', ['menunggu_verifikasi', 'terverifikasi', 'ditolak', 'void'])->default('menunggu_verifikasi');
            $table->unsignedInteger('dicatat_oleh');
            $table->unsignedInteger('diverifikasi_oleh')->nullable();
            $table->timestamp('diverifikasi_pada')->nullable();
            $table->text('alasan_tolak')->nullable();
            $table->unsignedInteger('journal_entry_id')->nullable();
            $table->timestamps();

            $table->foreign('id_santri')->references('id')->on('santri')->restrictOnDelete();
            $table->foreign('id_tagihan')->references('id')->on('tagihan_santri')->restrictOnDelete();
            $table->foreign('dicatat_oleh')->references('id_pengguna')->on('users')->restrictOnDelete();
            $table->foreign('diverifikasi_oleh')->references('id_pengguna')->on('users')->nullOnDelete();
            $table->index('id_santri');
            $table->index('status');
            $table->index('journal_entry_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pembayaran_santri');
    }
};
