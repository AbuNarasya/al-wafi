<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PENGUNDURAN DIRI SANTRI AKTIF lewat portal wali (keputusan user 21 Okt 2026).
 *
 * Alur: wali mengajukan (+ surat) → wali kelas memverifikasi → kepala sekolah Ya/Tidak →
 * clearance paralel (keuangan · kesantrian · admin sekolah, + surat mutasi/SK) → siap dikeluarkan →
 * admin sekolah mengeluarkan (SantriService::mengundurkanDiri) di akhir bulan & menandai Dapodik.
 *
 *  • `kelas.id_wali_kelas`, `jenjang.id_kepala_sekolah` — siapa yang memutus tiap pengajuan.
 *  • `pengajuan_keluar` — satu baris per pengajuan, keputusan tiap tahap disimpan di kolomnya sendiri.
 *  • `dokumen_pengajuan_keluar` — surat pengajuan (wali), surat mutasi, SK, dll. (admin sekolah).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kelas', function (Blueprint $table) {
            $table->unsignedInteger('id_wali_kelas')->nullable();
            $table->foreign('id_wali_kelas')->references('id_pengguna')->on('users')->nullOnDelete();
        });
        Schema::table('jenjang', function (Blueprint $table) {
            $table->unsignedInteger('id_kepala_sekolah')->nullable();
            $table->foreign('id_kepala_sekolah')->references('id_pengguna')->on('users')->nullOnDelete();
        });

        Schema::create('pengajuan_keluar', function (Blueprint $table) {
            $table->increments('id');
            $table->string('nomor', 30)->unique();
            $table->unsignedInteger('id_santri');
            $table->unsignedInteger('id_wali');
            $table->string('diajukan_peran', 10);
            $table->text('alasan');
            $table->date('rencana_tanggal')->nullable();
            $table->enum('status', ['menunggu_wali_kelas', 'dikembalikan', 'menunggu_kepala_sekolah', 'ditolak',
                'clearance', 'siap_dikeluarkan', 'selesai', 'dibatalkan']);
            foreach (['wali_kelas', 'kepala_sekolah', 'keuangan', 'kesantrian', 'admin'] as $tahap) {
                $table->unsignedInteger("{$tahap}_oleh")->nullable();
                $table->timestamp("{$tahap}_pada")->nullable();
                $table->text("{$tahap}_catatan")->nullable();
            }
            $table->unsignedInteger('dikeluarkan_oleh')->nullable();
            $table->timestamp('dikeluarkan_pada')->nullable();
            $table->unsignedInteger('dapodik_oleh')->nullable();
            $table->timestamp('dapodik_pada')->nullable();
            $table->timestamps();

            $table->foreign('id_santri')->references('id')->on('santri')->cascadeOnDelete();
            $table->foreign('id_wali')->references('id')->on('wali');
            $table->index('status');
        });

        Schema::create('dokumen_pengajuan_keluar', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_pengajuan');
            $table->enum('jenis', ['surat_pengajuan', 'surat_mutasi', 'surat_keputusan', 'lainnya']);
            $table->string('keterangan')->nullable();
            $table->string('nama_asli');
            $table->string('path')->unique();
            $table->string('mime', 100);
            $table->integer('ukuran');
            $table->unsignedInteger('diunggah_oleh')->nullable();
            $table->unsignedInteger('diunggah_wali')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('id_pengajuan')->references('id')->on('pengajuan_keluar')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dokumen_pengajuan_keluar');
        Schema::dropIfExists('pengajuan_keluar');
        Schema::table('jenjang', function (Blueprint $t) {
            $t->dropForeign(['id_kepala_sekolah']);
            $t->dropColumn('id_kepala_sekolah');
        });
        Schema::table('kelas', function (Blueprint $t) {
            $t->dropForeign(['id_wali_kelas']);
            $t->dropColumn('id_wali_kelas');
        });
    }
};
