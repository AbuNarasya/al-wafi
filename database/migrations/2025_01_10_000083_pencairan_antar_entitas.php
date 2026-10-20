<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PENCAIRAN ANTAR-ENTITAS BELANJA KARTU — Fase 3c-3 (keputusan user 20 Okt 2026, arah B).
 *
 *  • ERP Al Wafi (`pencairan_toko`, PWM-): membayar hutangnya ke Wafi Mart sebesar belanja kartu bersih yang sudah
 *    dijurnal malam (D Hutang ke Wafi Mart / K Bank). Transaksi penghubung yang tercakup bertanda `id_pencairan_toko`.
 *  • ERP Wafi Mart (`penerimaan_al_wafi`): mencatat uangnya masuk (D Bank / K Piutang ke Al Wafi) dengan nomor
 *    pencairan yang SAMA, lalu memberi tahu Al Wafi lewat penghubung — sejak itu pencairannya tak bisa dibatalkan.
 * Kedua tabel ada di kedua instans (satu kode); masing-masing hanya terisi di instansnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pencairan_toko', function (Blueprint $table) {
            $table->increments('id');
            $table->string('nomor', 30)->unique();
            $table->date('sampai_tanggal');
            $table->unsignedInteger('jumlah_transaksi');
            $table->decimal('total', 18, 2);
            $table->string('kode_coa_bank');
            $table->enum('status', ['aktif', 'batal'])->default('aktif');
            $table->string('alasan_batal')->nullable();
            $table->unsignedInteger('journal_entry_id')->nullable();
            $table->unsignedInteger('dibuat_oleh');
            $table->timestamp('diterima_toko_pada')->nullable();
            $table->timestamps();

            $table->foreign('kode_coa_bank')->references('kode_coa')->on('coa_detail');
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->nullOnDelete();
            $table->foreign('dibuat_oleh')->references('id_pengguna')->on('users');
        });

        Schema::table('transaksi_dompet_toko', function (Blueprint $table) {
            $table->unsignedInteger('id_pencairan_toko')->nullable();
            $table->foreign('id_pencairan_toko')->references('id')->on('pencairan_toko')->nullOnDelete();
        });

        Schema::create('penerimaan_al_wafi', function (Blueprint $table) {
            $table->increments('id');
            $table->string('nomor_pencairan', 30)->unique();   // nomor PWM- dari ERP Al Wafi
            $table->date('sampai_tanggal');
            $table->date('tanggal');
            $table->decimal('total', 18, 2);
            $table->string('kode_coa_bank');
            $table->unsignedInteger('journal_entry_id')->nullable();
            $table->unsignedInteger('diterima_oleh');
            $table->timestamps();

            $table->foreign('kode_coa_bank')->references('kode_coa')->on('coa_detail');
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->nullOnDelete();
            $table->foreign('diterima_oleh')->references('id_pengguna')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penerimaan_al_wafi');
        Schema::table('transaksi_dompet_toko', function (Blueprint $table) {
            $table->dropForeign(['id_pencairan_toko']);
            $table->dropColumn('id_pencairan_toko');
        });
        Schema::dropIfExists('pencairan_toko');
    }
};
