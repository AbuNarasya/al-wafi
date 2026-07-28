<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * santri — Calon → santri. no_pendaftaran terbit saat mendaftar; nis saat aktif.
 * nominal_spp per anak (jalur beasiswa/keringanan). status = daur hidup PPSB.
 */
return new class extends Migration
{
    public function up(): void
    {
        $statusSantri = ['calon', 'terbayar', 'terverifikasi', 'diseleksi', 'diterima', 'lolos_kesehatan', 'aktif', 'alumni', 'tidak_lulus', 'gagal_medcheck', 'mengundurkan_diri', 'keluar'];

        Schema::create('santri', function (Blueprint $table) use ($statusSantri) {
            $table->increments('id');
            $table->string('no_pendaftaran')->unique();
            $table->string('nis')->nullable()->unique();
            $table->string('nama');
            $table->enum('jenis_kelamin', ['L', 'P']);
            $table->string('tempat_lahir')->nullable();
            $table->date('tanggal_lahir')->nullable();
            $table->string('nisn')->nullable();
            $table->string('asal_sekolah')->nullable();
            $table->text('alamat_sekolah_asal')->nullable();
            $table->string('kepala_sekolah_asal')->nullable();
            $table->string('kode_jenjang')->nullable();
            $table->integer('angkatan')->nullable();
            $table->string('jalur')->default('reguler'); // reguler | tahfizh | beasiswa
            $table->integer('gelombang')->default(1);
            $table->enum('sumber_informasi', ['medsos', 'iklan', 'rekomendasi', 'website', 'lainnya'])->nullable();
            $table->string('sumber_informasi_lain')->nullable();
            $table->decimal('nominal_spp', 18, 2)->nullable();
            $table->text('keterangan_spp')->nullable();
            $table->enum('status', $statusSantri)->default('calon');
            $table->unsignedInteger('id_wali');
            $table->timestamps();

            $table->foreign('id_wali')->references('id')->on('wali')->restrictOnDelete();
            $table->index('status');
            $table->index('id_wali');
            $table->index('nama');
            $table->index('nisn');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('santri');
    }
};
