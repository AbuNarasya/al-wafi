<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tahun_ajaran — Master tahun ajaran PPSB (mis. "2026/2027"). Menjadi rujukan
 * jenis_biaya, jalur_pendaftaran, potongan_gelombang, target_santri, dan
 * santri (dipilih saat registrasi calon). default_pendaftaran = TA terpilih
 * otomatis di form registrasi (maksimal satu, dijaga service).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tahun_ajaran', function (Blueprint $table) {
            $table->increments('id');
            $table->string('kode')->unique(); // "2026/2027" — nilai yang disimpan tabel perujuk
            $table->date('tanggal_mulai')->nullable();
            $table->date('tanggal_selesai')->nullable();
            $table->enum('status', ['aktif', 'nonaktif'])->default('aktif');
            $table->boolean('default_pendaftaran')->default(false);
            $table->text('keterangan')->nullable();
            $table->timestamps();

            $table->index(['status', 'default_pendaftaran']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tahun_ajaran');
    }
};
