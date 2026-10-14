<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PASSWORD PORTAL WALI (keputusan user 14 Okt 2026): kode OTP email hanya sekali —
 * saat pertama masuk (atau lupa password); sesudahnya email + password.
 *
 *  • Password PER ORANG (ayah / ibu / wali), seperti PIN dompet — satu baris
 *    keluarga dipakai beberapa orang, masing-masing dengan email & password sendiri.
 *  • "Ingat perangkat" tak lagi kedaluwarsa 90 hari: `kedaluwarsa_pada` boleh
 *    kosong = selamanya, sampai keluar / dicabut / password atau email diganti.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sandi_wali', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_wali');
            $table->enum('peran', ['ayah', 'ibu', 'wali']);
            $table->string('sandi_hash');
            $table->timestamps();

            $table->foreign('id_wali')->references('id')->on('wali')->cascadeOnDelete();
            $table->unique(['id_wali', 'peran']);
        });

        DB::statement('ALTER TABLE perangkat_wali ALTER COLUMN kedaluwarsa_pada DROP NOT NULL');
        // Perangkat yang masih berlaku ikut menjadi "selamanya".
        DB::table('perangkat_wali')->whereNull('dicabut_pada')->where('kedaluwarsa_pada', '>', now())
            ->update(['kedaluwarsa_pada' => null]);
    }

    public function down(): void
    {
        DB::table('perangkat_wali')->whereNull('kedaluwarsa_pada')->update(['kedaluwarsa_pada' => now()->addDays(90)]);
        DB::statement('ALTER TABLE perangkat_wali ALTER COLUMN kedaluwarsa_pada SET NOT NULL');
        Schema::dropIfExists('sandi_wali');
    }
};
