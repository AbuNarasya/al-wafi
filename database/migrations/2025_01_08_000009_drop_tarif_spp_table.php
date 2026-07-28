<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tarif_spp DIBUANG — tarif SPP kini terpusat di master jenis_biaya (baris
 * bertipe `spp` menyimpan nominal + kode_jenjang + tahun_ajaran), sejalan
 * dengan registrasi & uang pangkal. Jaminan "satu tarif per jenjang" yang dulu
 * datang dari primary key kini ditegakkan JenisBiayaService::assertTarifSppTunggal().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('tarif_spp');
    }

    public function down(): void
    {
        Schema::create('tarif_spp', function (Blueprint $table) {
            $table->string('kode_jenjang')->primary();
            $table->string('kode_jenis');
            $table->decimal('nominal', 18, 2);
            $table->enum('status', ['aktif', 'nonaktif'])->default('aktif');
            $table->timestamps();

            $table->foreign('kode_jenis')->references('kode')->on('jenis_biaya')->restrictOnDelete();
            $table->foreign('kode_jenjang')->references('kode')->on('jenjang')->restrictOnDelete();
            $table->index('kode_jenis');
        });
    }
};
