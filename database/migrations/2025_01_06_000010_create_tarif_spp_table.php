<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** tarif_spp — Tarif SPP per jenjang. Keringanan/jalur = Santri.nominal_spp. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tarif_spp', function (Blueprint $table) {
            $table->string('kode_jenjang')->primary();
            $table->string('nama_jenjang');
            $table->string('kode_jenis'); // JenisBiaya bertipe spp
            $table->decimal('nominal', 18, 2);
            $table->enum('status', ['aktif', 'nonaktif'])->default('aktif');
            $table->timestamps();

            $table->foreign('kode_jenis')->references('kode')->on('jenis_biaya')->restrictOnDelete();
            $table->index('kode_jenis');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tarif_spp');
    }
};
