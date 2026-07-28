<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * dokumen_santri — Metadata berkas unggahan (isi file di FileStorage, bukan DB).
 * Pengunggah: staff (diunggah_oleh) ATAU wali portal (diunggah_wali). Hanya created_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        $jenis = ['ktp_ayah', 'ktp_ibu', 'ktp_wali', 'akta_kelahiran', 'kartu_keluarga', 'foto', 'surat_keterangan_sekolah', 'hasil_medcheck', 'lainnya'];

        Schema::create('dokumen_santri', function (Blueprint $table) use ($jenis) {
            $table->increments('id');
            $table->unsignedInteger('id_santri');
            $table->enum('jenis', $jenis);
            $table->enum('tahap', ['registrasi', 'pasca_lulus']);
            $table->string('nama_asli');
            $table->string('path')->unique();
            $table->string('mime');
            $table->integer('ukuran'); // byte
            $table->string('hash_sha256')->nullable();
            $table->text('keterangan')->nullable();
            $table->unsignedInteger('diunggah_oleh')->nullable();
            $table->unsignedInteger('diunggah_wali')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('id_santri')->references('id')->on('santri')->cascadeOnDelete();
            $table->foreign('diunggah_oleh')->references('id_pengguna')->on('users')->nullOnDelete();
            $table->index(['id_santri', 'tahap']);
            $table->index('jenis');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dokumen_santri');
    }
};
