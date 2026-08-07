<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KELUARGA A — tagihan yang lahir dari PEMAKAIAN. Laundry contohnya.
 *
 * Besarannya bukan angka langganan melainkan `tarif_satuan × kuantitas`, dan
 * kuantitasnya berubah tiap periode. Karena itu tak ada nominal yang bisa
 * disimpan di daftar peserta, dan tak ada jadwal yang bisa menerbitkannya
 * sendiri — tak ada angka untuk diterbitkan sebelum ada yang menimbang.
 *
 * `kuota_gratis` = jatah bebas biaya per santri per periode (20 kg untuk
 * laundry). Tagihan terbit HANYA atas kelebihannya; pemakaian di bawah kuota
 * tidak menerbitkan tagihan sama sekali — bukan tagihan bernilai nol, karena
 * tagihan nol tetap harus dibaca, dibayar, dan dilaporkan oleh seseorang.
 *
 * `setoran_pemakaian.id_tagihan` adalah PENANDA SUDAH TERTAGIH, dan ia yang
 * membuat aturan "setoran susulan ikut periode berikutnya" bekerja tanpa
 * membanding-bandingkan tanggal: penerbitan hanya menyapu setoran yang belum
 * bertanda, jadi baris yang telat dicatat otomatis terbawa ke penerbitan
 * berikutnya alih-alih hilang atau tertagih dua kali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jenis_biaya', function (Blueprint $table) {
            $table->decimal('tarif_satuan', 18, 2)->nullable();
            $table->string('nama_satuan')->nullable();
            // Boleh kosong = tak ada jatah gratis; nol pun sah dan berarti sama.
            $table->decimal('kuota_gratis', 12, 2)->nullable();
        });

        Schema::create('setoran_pemakaian', function (Blueprint $table) {
            $table->increments('id');
            $table->string('kode_jenis');
            $table->unsignedInteger('id_santri');
            $table->date('tanggal');
            $table->decimal('kuantitas', 12, 2);
            $table->string('catatan')->nullable();
            // Tagihan yang sudah memungut baris ini. NULL = belum tertagih.
            $table->unsignedInteger('id_tagihan')->nullable();
            $table->unsignedInteger('dicatat_oleh')->nullable();
            $table->timestamps();

            $table->foreign('kode_jenis')->references('kode')->on('jenis_biaya')->cascadeOnDelete();
            $table->foreign('id_santri')->references('id')->on('santri')->cascadeOnDelete();
            // Tagihan yang dihapus TIDAK ikut menghapus setorannya — timbangannya
            // benar-benar terjadi. Penandanya dilepas supaya barisnya kembali
            // masuk hitungan periode berikutnya.
            $table->foreign('id_tagihan')->references('id')->on('tagihan_santri')->nullOnDelete();
            $table->foreign('dicatat_oleh')->references('id_pengguna')->on('users')->nullOnDelete();

            // Penyapuan penerbitan selalu bertanya "yang belum tertagih, milik
            // jenis ini, sampai tanggal sekian" — indeksnya mengikuti itu.
            $table->index(['kode_jenis', 'id_tagihan', 'tanggal']);
            $table->index(['id_santri', 'tanggal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('setoran_pemakaian');

        Schema::table('jenis_biaya', function (Blueprint $table) {
            $table->dropColumn(['tarif_satuan', 'nama_satuan', 'kuota_gratis']);
        });
    }
};
