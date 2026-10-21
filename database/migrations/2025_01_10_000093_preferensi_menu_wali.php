<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MENU UTAMA PORTAL pilihan wali (keputusan user 21 Okt 2026): paling banyak 7 ikon di beranda (+ "Menu Lengkap"),
 * per ORANG (ayah/ibu/wali bisa berbeda). `menu` = daftar kunci MenuPortal berurutan. Tak ada baris = bawaan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preferensi_menu_wali', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_wali');
            $table->string('peran', 10);
            $table->json('menu');
            $table->timestamps();

            $table->unique(['id_wali', 'peran']);
            $table->foreign('id_wali')->references('id')->on('wali')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preferensi_menu_wali');
    }
};
