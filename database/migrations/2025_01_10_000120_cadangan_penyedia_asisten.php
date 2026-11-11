<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CADANGAN PENYEDIA AI (keputusan user 11 Nov 2026): bila penyedia utama gagal (kuota per menit paket gratis
 * penuh, gangguan, waktu habis), pertanyaan yang SAMA langsung dicoba ke penyedia lain yang kuncinya terpasang,
 * baru sesudah itu jatuh ke pencarian artikel. Nilai awal nyala — tanpa kunci penyedia lain, tak berpengaruh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pengaturan_asisten_wali', function (Blueprint $table) {
            $table->boolean('cadangan')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('pengaturan_asisten_wali', function (Blueprint $table) {
            $table->dropColumn('cadangan');
        });
    }
};
