<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAFI MART — RETUR PENJUALAN (keputusan user 19 Okt 2026: barang RUSAK tidak kembali ke
 * stok; PIN pengawas wajib; batas waktu per toko).
 *
 *  • Retur menempel ke struk ASALNYA (jumlahnya tak bisa melebihi yang dibeli dikurangi
 *    yang sudah diretur) dan ke SHIFT YANG SEDANG BUKA — uangnya keluar dari laci shift itu,
 *    walau struknya dari hari lain. Karena itu `shift_kasir.total_retur` ikut perhitungan
 *    tutup shift: seharusnya di laci = modal + penjualan tunai − retur.
 *  • Nilai uang kembali = bagian struk asal yang SUDAH dibayar (diskon baris & diskon struk
 *    ikut terbagi), bukan harga hari ini. HPP yang kembali ke stok = harga pokok FIFO baris
 *    asalnya, persis — bukan harga rata-rata saat retur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lokasi_persediaan', function (Blueprint $table) {
            $table->unsignedSmallInteger('batas_hari_retur')->default(7);
        });

        Schema::table('shift_kasir', function (Blueprint $table) {
            $table->decimal('total_retur', 18, 2)->default(0)->after('total_tunai');
        });

        Schema::create('retur_penjualan', function (Blueprint $table) {
            $table->increments('id_retur');
            $table->string('nomor')->unique();
            $table->unsignedInteger('id_penjualan');
            $table->unsignedInteger('id_shift');
            $table->string('kode_lokasi', 20);
            $table->timestamp('waktu');
            $table->decimal('total', 18, 2);
            // Harga pokok barang LAYAK yang kembali ke stok (dibalik dari HPP saat tutup shift).
            $table->decimal('hpp_kembali', 18, 2)->default(0);
            $table->string('alasan');
            $table->unsignedInteger('id_kasir');
            $table->unsignedInteger('disetujui_oleh');
            $table->timestamps();

            $table->foreign('id_penjualan')->references('id_penjualan')->on('penjualan');
            $table->foreign('id_shift')->references('id_shift')->on('shift_kasir');
            $table->foreign('kode_lokasi')->references('kode')->on('lokasi_persediaan');
            $table->foreign('disetujui_oleh')->references('id_pengguna')->on('users');
            $table->index('id_shift');
        });

        Schema::create('retur_penjualan_detail', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_retur');
            $table->unsignedInteger('id_penjualan_detail');
            $table->string('kode_persediaan');
            // Dalam satuan struk asal (mis. 1 DUS) dan satuan dasar.
            $table->decimal('kuantiti_input', 18, 4);
            $table->decimal('kuantiti', 18, 4);
            $table->enum('kondisi', ['layak', 'rusak']);
            $table->decimal('nilai', 18, 2);
            $table->decimal('hpp', 18, 2)->default(0);

            $table->foreign('id_retur')->references('id_retur')->on('retur_penjualan')->cascadeOnDelete();
            $table->foreign('id_penjualan_detail')->references('id')->on('penjualan_detail');
            $table->foreign('kode_persediaan')->references('kode_persediaan')->on('inventory');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retur_penjualan_detail');
        Schema::dropIfExists('retur_penjualan');
        Schema::table('shift_kasir', function (Blueprint $table) {
            $table->dropColumn('total_retur');
        });
        Schema::table('lokasi_persediaan', function (Blueprint $table) {
            $table->dropColumn('batas_hari_retur');
        });
    }
};
