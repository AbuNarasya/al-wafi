<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * persetujuan_term — Surat pernyataan wali (snapshot teks + tanda tangan
 * elektronik). PERNYATAAN saja — tidak menggerakkan tagihan otomatis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('persetujuan_term', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_santri')->unique();
            $table->unsignedInteger('id_wali');
            $table->unsignedInteger('id_term_template');
            $table->text('isi_umum');
            $table->text('isi_khusus')->nullable();
            $table->string('hash_sha256');
            $table->string('metode_ttd')->default('otp_whatsapp');
            $table->enum('status', ['menunggu', 'disetujui', 'batal'])->default('menunggu');
            $table->timestamp('disetujui_pada')->nullable();
            $table->string('penanda_tangan_nama')->nullable();
            $table->string('penanda_tangan_telepon')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('otp_terverifikasi_pada')->nullable();
            $table->string('bukti_provider_ref')->nullable();
            $table->string('pdf_path')->nullable();
            $table->unsignedInteger('disusun_oleh');
            $table->timestamps();

            $table->foreign('id_santri')->references('id')->on('santri')->cascadeOnDelete();
            $table->foreign('id_wali')->references('id')->on('wali')->restrictOnDelete();
            $table->foreign('id_term_template')->references('id')->on('term_template')->restrictOnDelete();
            $table->foreign('disusun_oleh')->references('id_pengguna')->on('users')->restrictOnDelete();
            $table->index('id_wali');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('persetujuan_term');
    }
};
