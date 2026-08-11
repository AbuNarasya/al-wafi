<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * lampiran_dokumen — berkas pendukung dokumen keuangan (invoice, penawaran,
 * nota, kwitansi, bukti transfer). Metadata di sini, ISI berkas di disk.
 *
 * POLIMORFIK (`jenis_dokumen` + `id_dokumen`), meniru `approval_instance` yang
 * sudah memakai pasangan kolom yang sama. Karena itu TIDAK ada kunci asing ke
 * tabel induk: satu tabel melayani pengajuan pembayaran, uang muka operasional,
 * penyelesaian uang muka — dan modul mana pun berikutnya tanpa tabel baru.
 * Gantinya, penghapusan induk harus ikut membuang lampirannya sendiri
 * (LampiranService::hapusMilik).
 *
 * Kolom `disk` disimpan PER BARIS, bukan dibaca dari config saat mengunduh:
 * kalau kelak pindah ke S3/R2, berkas lama tetap terbaca dari tempat lamanya
 * dan tak perlu dipindahkan serentak.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lampiran_dokumen', function (Blueprint $table) {
            $table->increments('id');
            $table->string('jenis_dokumen', 50);
            $table->string('id_dokumen', 50);
            $table->string('nama_asli');
            $table->string('path')->unique();
            $table->string('disk', 30)->default('local');
            $table->string('mime');
            $table->integer('ukuran'); // byte
            $table->string('hash_sha256')->nullable();
            $table->string('keterangan')->nullable();
            $table->unsignedInteger('diunggah_oleh')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('diunggah_oleh')->references('id_pengguna')->on('users')->nullOnDelete();
            $table->index(['jenis_dokumen', 'id_dokumen']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lampiran_dokumen');
    }
};
