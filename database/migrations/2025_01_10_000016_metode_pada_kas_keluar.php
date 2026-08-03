<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Metode pembayaran yang BENAR-BENAR dipakai saat Kas Keluar diposting.
 *
 * Perintah Pembayaran mencatat metode yang DIRENCANAKAN pejabat; tanpa kolom ini
 * tak ada yang bisa dibandingkan dengannya, dan laporan kepatuhan hanya bisa
 * bercerita tentang nominal, tanggal, dan rekening. Boleh kosong: voucher lama
 * (dan yang tidak berasal dari perintah) tak pernah mengisinya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_out', function (Blueprint $table) {
            $table->string('metode')->nullable()->after('kode_rekening'); // transfer | teller | tunai
        });
    }

    public function down(): void
    {
        Schema::table('cash_out', function (Blueprint $table) {
            $table->dropColumn('metode');
        });
    }
};
