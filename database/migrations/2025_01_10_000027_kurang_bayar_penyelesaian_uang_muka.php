<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `pengajuan_pembayaran.sisa_kurang_bayar` — kekurangan penyelesaian uang muka
 * yang BELUM dibayar.
 *
 * Sebelum ini, penyelesaian yang realisasinya melampaui uang muka langsung
 * MENGKREDIT KAS sebesar kekurangannya, seolah uangnya sudah keluar. Padahal
 * belum dibayar sama sekali: saldo kas & "dana bisa dipakai" jadi lebih rendah
 * dari kenyataan, dan kewajiban ke pemohon tak tercatat di mana pun.
 *
 * Kolomnya BARU, tidak menumpang `sisa_hutang`, karena pada dokumen penyelesaian
 * kolom itu sudah memikul arti lain: ia menyimpan nominal uang muka yang
 * diselesaikan, dan dibaca saat penyelesaian dibatalkan untuk mengembalikan
 * angka ke pool uang muka (lihat PengajuanPembayaranService::void). Menumpang di
 * sana akan membuat pembatalan mengembalikan angka yang salah — kekeliruan diam
 * yang baru ketahuan saat saldo uang muka tak lagi cocok.
 *
 * Dokumen lama tidak disentuh: yang sudah berstatus `selesai` tetap 0 dan tak
 * pernah muncul sebagai kewajiban.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pengajuan_pembayaran', function (Blueprint $table) {
            $table->decimal('sisa_kurang_bayar', 18, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('pengajuan_pembayaran', function (Blueprint $table) {
            $table->dropColumn('sisa_kurang_bayar');
        });
    }
};
