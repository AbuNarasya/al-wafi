<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SAKLAR METODE PEMBAYARAN PORTAL WALI (keputusan user 9 Nov 2026).
 *
 * Satu baris. Tiap metode di luar Dompet Wali (yang selalu aktif) bisa dinyalakan/dimatikan oleh pemegang
 * hak `metode-pembayaran` — Transfer + bukti dipakai sebagai CADANGAN saat payment gateway / VA bermasalah.
 * Saklar hanya menahan pesanan BARU: VA yang sudah terbit, bukti yang menunggu verifikasi, dan notifikasi
 * bank tetap diproses. Nilai awal semua nyala supaya perilaku tak berubah saat dirilis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengaturan_metode_bayar', function (Blueprint $table) {
            $table->increments('id');
            $table->boolean('midtrans')->default(true);
            $table->boolean('transfer')->default(true);
            // Pesan dari staf yang tampil di layar bayar wali (mis. "VA sedang gangguan"). Tak diterjemahkan.
            $table->text('pengumuman')->nullable();
            $table->unsignedInteger('diubah_oleh')->nullable();
            $table->foreign('diubah_oleh')->references('id_pengguna')->on('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::table('pengaturan_metode_bayar')->insert(['midtrans' => true, 'transfer' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('pengaturan_metode_bayar');
    }
};
