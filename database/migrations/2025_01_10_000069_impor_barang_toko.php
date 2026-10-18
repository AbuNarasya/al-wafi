<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IMPOR DATA AWAL WAFI MART (keputusan user 19 Okt 2026): master barang toko & stok
 * awal per lokasi lewat menu Impor Data Awal.
 *
 * `id_batch` menandai baris yang LAHIR dari sebuah impor — barang, dan kategori/merk
 * yang dibuat otomatis dari namanya — supaya batch yang keliru bisa dibatalkan utuh
 * tanpa menyentuh barang yang dibuat petugas lewat layar. Stok awal tak butuh kolom:
 * pergerakannya bersumber_ref "STOK-{id batch}" di kartu stok.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['inventory', 'kategori_barang', 'merk_barang'] as $tabel) {
            Schema::table($tabel, function (Blueprint $table) {
                $table->unsignedInteger('id_batch')->nullable();
                $table->foreign('id_batch')->references('id')->on('impor_batch')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['inventory', 'kategori_barang', 'merk_barang'] as $tabel) {
            Schema::table($tabel, function (Blueprint $table) {
                $table->dropForeign(['id_batch']);
                $table->dropColumn('id_batch');
            });
        }
    }
};
