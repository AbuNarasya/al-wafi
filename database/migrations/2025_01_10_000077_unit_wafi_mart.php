<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GRUP MENU WAFI MART (keputusan user 19 Okt 2026): laporan keuangan di grup itu
 * langsung tersaring unit bisnis Wafi Mart. Unitnya dipilih sekali di Pengaturan
 * Perusahaan. Neraca TIDAK ikut — kas & hutang dipusatkan ke unit penampung neraca;
 * neraca Wafi Mart sendiri baru ada saat instans terpisah (arah B).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->string('kode_unit_wafimart')->nullable();
            $table->foreign('kode_unit_wafimart')->references('kode_unit')->on('business_units')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropForeign(['kode_unit_wafimart']);
            $table->dropColumn('kode_unit_wafimart');
        });
    }
};
