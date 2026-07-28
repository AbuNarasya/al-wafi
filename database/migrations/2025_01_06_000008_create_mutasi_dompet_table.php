<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * mutasi_dompet — BUKU BESAR dompet. Perpindahan antar-dompet = 2 baris
 * berpasangan (id_pasangan). Hanya `topup` lahir menunggu_verifikasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        $jenis = ['topup', 'distribusi_keluar', 'distribusi_masuk', 'tabung_keluar', 'tabung_masuk', 'bayar_tagihan', 'jajan', 'tarik'];

        Schema::create('mutasi_dompet', function (Blueprint $table) use ($jenis) {
            $table->increments('id');
            $table->string('nomor')->unique();
            $table->enum('pemilik', ['wali', 'santri', 'tabungan']);
            $table->unsignedInteger('id_dompet');
            $table->enum('jenis', $jenis);
            $table->decimal('nominal', 18, 2);
            $table->decimal('saldo_setelah', 18, 2);
            $table->date('tanggal');
            $table->text('keterangan')->nullable();
            $table->enum('status', ['menunggu_verifikasi', 'terverifikasi', 'ditolak'])->default('terverifikasi');
            $table->string('kode_rekening')->nullable();
            $table->string('bukti_path')->nullable();
            $table->unsignedInteger('id_pasangan')->nullable();
            $table->unsignedInteger('dicatat_oleh');
            $table->unsignedInteger('diverifikasi_oleh')->nullable();
            $table->timestamp('diverifikasi_pada')->nullable();
            $table->text('alasan_tolak')->nullable();
            $table->unsignedInteger('journal_entry_id')->nullable();
            $table->timestamps();

            $table->foreign('dicatat_oleh')->references('id_pengguna')->on('users')->restrictOnDelete();
            $table->foreign('diverifikasi_oleh')->references('id_pengguna')->on('users')->nullOnDelete();
            $table->index(['pemilik', 'id_dompet']);
            $table->index('status');
            $table->index('tanggal');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mutasi_dompet');
    }
};
