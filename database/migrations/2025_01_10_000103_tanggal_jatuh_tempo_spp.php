<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * JATUH TEMPO SPP BAWAAN (keputusan user 3 Nov 2026): SPP yang terbit tanpa jatuh tempo tak pernah dianggap
 * menunggak — Penagihan Tunggakan kosong, pengingat ke wali tak berbunyi. Tanggal bawaan (1–31) diatur di
 * layar Reminder Tagihan; NULL = tak ada bawaan. Penerapannya ke tagihan lama dikerjakan saat disimpan
 * (App\Services\Modules\JatuhTempoSpp), bukan di migrasi — tanggalnya baru diketahui sesudah diisi user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reminder_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('tanggal_jatuh_tempo_spp')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reminder_settings', function (Blueprint $table) {
            $table->dropColumn('tanggal_jatuh_tempo_spp');
        });
    }
};
