<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PENARIKAN TUNAI KEGIATAN — tahap E2 (keputusan user 19 Okt 2026).
 *
 *  • Ambil dana: D Kas Tunai Kegiatan / K Bank sebesar total yang disetujui.
 *  • Distribusi (tap kartu / manual beralasan): saldo dompet berkurang + mutasi `tarik`,
 *    TANPA jurnal — jurnalnya ringkas saat sesi selesai, pola tutup shift kasir.
 *  • Selesai: uang fisik sisa WAJIB sama dengan hitungan (diambil − diserahkan);
 *    D Titipan Santri (diserahkan) + D Bank (sisa disetor) / K Kas Tunai Kegiatan (diambil).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sesi_tarik_tunai', function (Blueprint $table) {
            $table->decimal('total_diambil', 18, 2)->nullable();
            $table->timestamp('dana_diambil_pada')->nullable();
            $table->unsignedInteger('dana_diambil_oleh')->nullable();
            $table->unsignedInteger('journal_ambil_id')->nullable();
            $table->decimal('total_diserahkan', 18, 2)->nullable();
            $table->decimal('sisa_disetor', 18, 2)->nullable();
            $table->timestamp('selesai_pada')->nullable();
            $table->unsignedInteger('selesai_oleh')->nullable();
            $table->unsignedInteger('journal_selesai_id')->nullable();

            $table->foreign('journal_ambil_id')->references('id')->on('journal_entries')->nullOnDelete();
            $table->foreign('journal_selesai_id')->references('id')->on('journal_entries')->nullOnDelete();
        });

        Schema::table('pengajuan_tarik_tunai', function (Blueprint $table) {
            $table->timestamp('diserahkan_pada')->nullable();
            $table->unsignedInteger('diserahkan_oleh')->nullable();
            $table->enum('cara_serah', ['kartu', 'manual'])->nullable();
            $table->string('alasan_manual')->nullable();
            $table->unsignedInteger('id_mutasi_dompet')->nullable();

            $table->foreign('id_mutasi_dompet')->references('id')->on('mutasi_dompet')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pengajuan_tarik_tunai', function (Blueprint $table) {
            $table->dropForeign(['id_mutasi_dompet']);
            $table->dropColumn(['diserahkan_pada', 'diserahkan_oleh', 'cara_serah', 'alasan_manual', 'id_mutasi_dompet']);
        });
        Schema::table('sesi_tarik_tunai', function (Blueprint $table) {
            $table->dropForeign(['journal_ambil_id']);
            $table->dropForeign(['journal_selesai_id']);
            $table->dropColumn(['total_diambil', 'dana_diambil_pada', 'dana_diambil_oleh', 'journal_ambil_id', 'total_diserahkan',
                'sisa_disetor', 'selesai_pada', 'selesai_oleh', 'journal_selesai_id']);
        });
    }
};
