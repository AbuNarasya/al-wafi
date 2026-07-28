<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * target_santri.target_l / target_p — target dipecah per jenis kelamin agar
 * Dashboard PPSB bisa membandingkan plan vs aktual per jenjang DAN per jenis
 * kelamin (sebelumnya target hanya per jenjang, jadi sisi plan tak punya
 * pembanding L/P).
 *
 * Kolom `target` DIPERTAHANKAN sebagai total: baris lama tetap sah, dan form
 * mengisinya otomatis dari L+P. Nilai awal L/P sengaja NULL — artinya "belum
 * dirinci", bukan nol, supaya dashboard bisa membedakan target yang memang 0
 * dari target yang belum dipecah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('target_santri', function (Blueprint $table) {
            $table->integer('target_l')->nullable()->after('target');
            $table->integer('target_p')->nullable()->after('target_l');
        });
    }

    public function down(): void
    {
        Schema::table('target_santri', function (Blueprint $table) {
            $table->dropColumn(['target_l', 'target_p']);
        });
    }
};
