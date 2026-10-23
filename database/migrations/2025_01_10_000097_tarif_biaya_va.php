<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tarif biaya Virtual Account Midtrans yang dipungut dari wali (24 Okt 2026). Dulu satu angka di `.env`
 * (MIDTRANS_BIAYA_VA) yang hanya bisa diubah lewat server; kini diatur keuangan dari Pengaturan Perusahaan,
 * dengan tanggal mulai berlaku & riwayat. Tabel kosong = tetap memakai angka `.env` (lihat TarifVa).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tarif_biaya_va', function (Blueprint $table) {
            $table->increments('id');
            $table->decimal('biaya', 18, 2);
            $table->timestamp('berlaku_mulai');
            $table->text('catatan')->nullable();
            $table->unsignedInteger('dicatat_oleh')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->foreign('dicatat_oleh')->references('id_pengguna')->on('users')->nullOnDelete();
            $table->index('berlaku_mulai');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tarif_biaya_va');
    }
};
