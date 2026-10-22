<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bahasa portal wali per ORANG (ayah / ibu / wali) — keputusan user 22 Okt 2026: portal tiga bahasa
 * (Indonesia, Inggris, Arab). Disimpan di server supaya ikut ke HP lain; sebelum masuk, pilihannya
 * hanya di sesi. Tak ada baris = bahasa Indonesia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bahasa_wali', function (Blueprint $table) {
            $table->unsignedInteger('id_wali');
            $table->string('peran', 10);
            $table->string('bahasa', 5);
            $table->timestamps();
            $table->primary(['id_wali', 'peran']);
            $table->foreign('id_wali')->references('id')->on('wali')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bahasa_wali');
    }
};
