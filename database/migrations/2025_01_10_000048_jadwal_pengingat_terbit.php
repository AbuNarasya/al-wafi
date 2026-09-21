<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PENGINGAT PENERBITAN — menepuk bahu petugas agar menyusun & memeriksa draft.
 *
 * Bukan penjadwal penerbitan (itu `batch_tagihan`). Ia tak pernah menerbitkan
 * apa pun; ia hanya menerbitkan NOTIFIKASI.
 *
 * ══ BERJENIS TUGAS, DAN ITU PERMINTAAN USER ══
 * Notifikasinya tak bisa didiamkan dengan "tandai dibaca". Ia reda hanya bila:
 *  • petugas menekan "sudah saya kerjakan" (baris di konfirmasi_pengingat_terbit), ATAU
 *  • batch untuk periode itu memang sudah diotorisasi — supaya tak perlu
 *    mengonfirmasi dua kali hal yang sama.
 *
 * ══ TANPA KOLOM JAM, DAN ITU DISENGAJA ══
 * Notifikasi di aplikasi ini hanya terlihat DI DALAM aplikasi — tak ada surel,
 * tak ada push. Jadi "dikirim pukul 07:00" tidak membuatnya sampai lebih cepat;
 * yang menentukan tetap kapan petugasnya masuk. Menambah kolom jam hanya
 * menambah satu hal yang bisa salah disetel tanpa menambah satu pun manfaat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jadwal_pengingat_terbit', function (Blueprint $table) {
            $table->increments('id');

            // Modul yang diingatkan — menentukan siapa penerimanya (pemegang hak
            // modul itu) dan batch mana yang dianggap "sudah dikerjakan".
            $table->enum('modul', ['spp', 'daftar_ulang', 'tagihan_lain']);
            // Hanya untuk tagihan_lain: satu pesantren bisa punya beberapa layanan
            // (laundry, ekskul) yang iramanya berbeda-beda.
            $table->string('kode_jenis')->nullable();

            $table->string('judul');
            $table->text('catatan')->nullable();

            $table->enum('irama', ['bulanan', 'tahunan'])->default('bulanan');
            // 1–31, atau 0 = HARI TERAKHIR bulan itu. Nol dipakai sebagai penanda
            // karena "tanggal 31" akan meleset di bulan yang hanya 30 hari — dan
            // melesetnya berupa pengingat yang tak pernah terkirim, bukan galat.
            $table->unsignedSmallInteger('tanggal')->default(1);
            // Hanya untuk irama tahunan: bulan sasarannya (1–12).
            $table->unsignedSmallInteger('bulan')->nullable();
            // Ditepuk berapa hari SEBELUM tanggal itu. Nol = tepat pada harinya.
            $table->unsignedSmallInteger('hari_sebelum')->default(0);

            $table->boolean('aktif')->default(true);
            $table->timestamps();

            $table->foreign('kode_jenis')->references('kode')->on('jenis_biaya')->restrictOnDelete();
            $table->index(['aktif', 'modul']);
        });

        Schema::create('konfirmasi_pengingat_terbit', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_jadwal');
            // "2026-09" untuk bulanan, "2026" untuk tahunan.
            $table->string('periode');
            $table->unsignedInteger('oleh');
            $table->timestamp('pada');

            $table->foreign('id_jadwal')->references('id')->on('jadwal_pengingat_terbit')->cascadeOnDelete();
            $table->foreign('oleh')->references('id_pengguna')->on('users')->restrictOnDelete();

            // Sekali konfirmasi per periode. Tanpa ini, dua petugas yang menekan
            // tombol berbarengan melahirkan dua baris dan jejaknya jadi rancu.
            $table->unique(['id_jadwal', 'periode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('konfirmasi_pengingat_terbit');
        Schema::dropIfExists('jadwal_pengingat_terbit');
    }
};
