<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REKONSILIASI MALAM BELANJA KARTU — Fase 3c-2 (keputusan user 20 Okt 2026), di instans Wafi Mart.
 * Satu baris per tanggal: transaksi penghubung di ERP Al Wafi dicocokkan per NOMOR dengan struk/batal/retur toko.
 *
 *  • Potongan yatim (terpotong di Al Wafi, tanpa struk di toko) → DIKEMBALIKAN OTOMATIS (nomor WM-YATIM-<asal>).
 *  • Pengembalian tanpa retur/batal di toko, atau struk kartu yang tak dikenal Al Wafi → DITANDAI untuk diputus staf.
 * Aman diulang: baris tanggal itu ditimpa hasil terbaru, pengembaliannya idempoten di Al Wafi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rekonsiliasi_dompet_toko', function (Blueprint $table) {
            $table->increments('id');
            $table->date('tanggal')->unique();
            // cocok · dibereskan (hanya yatim yang sudah dikembalikan) · perlu_diputus · gagal (Al Wafi tak terjangkau)
            $table->enum('status', ['cocok', 'dibereskan', 'perlu_diputus', 'gagal']);
            $table->unsignedInteger('jumlah_transaksi')->default(0);
            $table->decimal('bersih_al_wafi', 18, 2)->default(0);   // potong − kembalikan yang berhasil di Al Wafi
            $table->unsignedInteger('jumlah_yatim')->default(0);
            $table->decimal('nilai_yatim', 18, 2)->default(0);
            $table->unsignedInteger('perlu_diputus')->default(0);
            $table->json('rincian')->nullable();                    // [{nomor, jenis, nominal, masalah, tindakan}]
            $table->string('pesan')->nullable();
            $table->timestamp('dijalankan_pada');
            $table->unsignedInteger('ditangani_oleh')->nullable();
            $table->timestamp('ditangani_pada')->nullable();
            $table->string('catatan_tangani')->nullable();
            $table->timestamps();

            $table->foreign('ditangani_oleh')->references('id_pengguna')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rekonsiliasi_dompet_toko');
    }
};
