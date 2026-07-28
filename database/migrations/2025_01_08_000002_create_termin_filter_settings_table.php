<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * termin_filter_settings — Pengaturan filter "Termin jatuh tempo — perlu
 * ditagih" di halaman Angsuran Uang Pangkal. Baris tunggal (singleton id=1).
 * pilihan_hari = daftar jendela hari di dropdown; default_hari = pilihan awal
 * saat halaman dibuka (0 = hanya yang lewat, selalu tersedia).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('termin_filter_settings', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('pilihan_hari')->default('7,14,30'); // CSV hari, terurut naik
            $table->unsignedInteger('default_hari')->default(7); // 0 = hanya yang lewat
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('termin_filter_settings');
    }
};
