<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Periode berlaku sebuah gelombang: kapan potongannya dibuka & ditutup.
 *
 * BEDA dengan `masa_berlaku_hari` yang sudah ada di tabel ini — itu tenggat PER
 * SANTRI sesudah tagihannya terbit ("bayar ≥50% dalam N hari"). Yang ini
 * batas waktu KEBIJAKANNYA sendiri.
 *
 * Kedaluwarsa TIDAK ditulis ke kolom `aktif` oleh penjadwal: produksi (Render
 * paket gratis) tak punya cron, jadi job harian tak akan pernah jalan di sana
 * dan potongan yang sudah lewat akan tetap terpakai. Berlakunya dinilai saat
 * dipakai — lihat PotonganGelombangService::potonganAktif(). Efek sampingnya
 * justru yang diinginkan: memperpanjang `berlaku_sampai` langsung menghidupkan
 * kembali potongannya, tanpa aksi kedua.
 *
 * Keduanya nullable: baris yang sudah ada tetap berlaku tanpa batas waktu,
 * persis seperti sebelum kolom ini ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('potongan_gelombang', function (Blueprint $table) {
            $table->date('berlaku_mulai')->nullable()->after('masa_berlaku_hari');
            $table->date('berlaku_sampai')->nullable()->after('berlaku_mulai');
        });
    }

    public function down(): void
    {
        Schema::table('potongan_gelombang', function (Blueprint $table) {
            $table->dropColumn(['berlaku_mulai', 'berlaku_sampai']);
        });
    }
};
