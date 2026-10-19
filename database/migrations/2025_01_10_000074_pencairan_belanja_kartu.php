<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PENCAIRAN BELANJA KARTU KE TOKO (D3) — keputusan user 19 Okt 2026: MINGGUAN.
 *
 * Saat tutup shift, belanja kartu sudah dibukukan D Titipan Uang Saku / K Pendapatan toko —
 * tetapi UANGNYA masih di rekening Al Wafi tempat titipan santri disimpan. Pencairan
 * memindahkannya ke kas/bank toko (Pindah Buku): D Kas Toko / K Rekening penampung.
 *
 *  • Yang dicairkan = struk kartu SELESAI dari shift yang SUDAH DITUTUP sampai tanggal itu,
 *    dikurangi retur struk kartu — termasuk retur atas struk yang sudah dicairkan minggu
 *    lalu (karena itu retur pun bertanda `id_pencairan`).
 *  • Saat Wafi Mart kelak berdiri sendiri (arah B), dokumen inilah yang menjadi utang–piutang
 *    antar-entitas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pencairan_kartu', function (Blueprint $table) {
            $table->increments('id_pencairan');
            $table->string('nomor')->unique();
            $table->string('kode_lokasi', 20);
            $table->date('sampai_tanggal');
            $table->decimal('total_belanja', 18, 2);
            $table->decimal('total_retur', 18, 2)->default(0);
            $table->decimal('total', 18, 2);
            $table->string('kode_coa_sumber');
            $table->string('kode_coa_tujuan');
            $table->enum('status', ['aktif', 'batal'])->default('aktif');
            $table->string('alasan_batal')->nullable();
            $table->unsignedInteger('journal_entry_id')->nullable();
            $table->unsignedInteger('dibuat_oleh')->nullable();
            $table->timestamps();

            $table->foreign('kode_lokasi')->references('kode')->on('lokasi_persediaan');
            $table->foreign('kode_coa_sumber')->references('kode_coa')->on('bank_accounts');
            $table->foreign('kode_coa_tujuan')->references('kode_coa')->on('bank_accounts');
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->nullOnDelete();
        });

        Schema::table('penjualan', function (Blueprint $table) {
            $table->foreign('id_pencairan')->references('id_pencairan')->on('pencairan_kartu')->nullOnDelete();
        });

        Schema::table('retur_penjualan', function (Blueprint $table) {
            $table->unsignedInteger('id_pencairan')->nullable();
            $table->foreign('id_pencairan')->references('id_pencairan')->on('pencairan_kartu')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('retur_penjualan', function (Blueprint $table) {
            $table->dropForeign(['id_pencairan']);
            $table->dropColumn('id_pencairan');
        });
        Schema::table('penjualan', function (Blueprint $table) {
            $table->dropForeign(['id_pencairan']);
        });
        Schema::dropIfExists('pencairan_kartu');
    }
};
