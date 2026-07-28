<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * reminder_settings — Pengaturan reminder tagihan mendekati jatuh tempo.
 * Baris tunggal (singleton id=1), pola sama dengan company_settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminder_settings', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->boolean('aktif')->default(true);
            $table->string('hari_sebelum')->default('7,3,1'); // CSV titik pengingat H-n (0 = hari-H)
            $table->boolean('sumber_tagihan_santri')->default(true);
            $table->boolean('sumber_invoice_vendor')->default(true);
            $table->boolean('sumber_angsuran_uang_pangkal')->default(true);
            $table->boolean('penerima_admin')->default(true);
            $table->boolean('penerima_tim_keuangan')->default(true);
            $table->boolean('penerima_akses_modul')->default(false);
            $table->string('jam_kirim', 5)->default('07:00');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_settings');
    }
};
