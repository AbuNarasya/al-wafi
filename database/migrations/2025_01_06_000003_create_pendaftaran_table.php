<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * pendaftaran — Berkas proses penerimaan (tes, med check, catatan panitia).
 * medcheck_ok=false MEMBATALKAN penerimaan (status → gagal_medcheck).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pendaftaran', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_santri')->unique();
            $table->date('tanggal');
            $table->boolean('verifikasi_ok')->default(false);
            $table->decimal('nilai_baca', 5, 2)->nullable();
            $table->decimal('nilai_akademik', 5, 2)->nullable();
            $table->text('wawancara_wali')->nullable();
            $table->text('wawancara_santri')->nullable();
            $table->boolean('medcheck_ok')->nullable();
            $table->boolean('dokumen_lengkap')->default(false);
            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->foreign('id_santri')->references('id')->on('santri')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pendaftaran');
    }
};
