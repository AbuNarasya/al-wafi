<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * jenjang — MASTER jenjang pendidikan (SD/SMP/MI/MTs/…). Sebelumnya daftar
 * jenjang diturunkan dari tarif_spp sehingga sebuah jenjang baru "ada" setelah
 * tarif SPP-nya dibuat. Kini jenjang berdiri sendiri dan menjadi rujukan
 * santri, jenis_biaya, potongan_gelombang, tarif_spp, dan target_santri.
 * `urutan` menentukan urutan tampil di dropdown (SD → SMP → SMA).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jenjang', function (Blueprint $table) {
            $table->string('kode')->primary();
            $table->string('nama');
            $table->integer('urutan')->default(0);
            $table->enum('status', ['aktif', 'nonaktif'])->default('aktif');
            $table->text('keterangan')->nullable();
            $table->timestamps();

            $table->index(['status', 'urutan']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jenjang');
    }
};
