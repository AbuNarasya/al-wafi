<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rekening TUJUAN pembayaran pada pengajuan — ke mana uangnya ditransfer.
 *
 * Namanya sengaja berakhiran `_tujuan`: kolom `kode_rekening` yang sudah ada di
 * tabel ini artinya justru sebaliknya — kas/rekening PESANTREN sebagai sumber
 * dana. Dua hal itu tak boleh tertukar.
 *
 * Nilainya SALINAN (snapshot), bukan rujukan ke buku rekening pemohon: yang
 * tercetak di dokumen harus tetap sama walau baris di buku rekening kemudian
 * disunting atau dihapus.
 *
 * pengajuan_rekening_riwayat mencatat setiap penyuntingan oleh keuangan saat
 * verifikasi. Mengganti nomor rekening setelah dokumen disetujui adalah modus
 * penipuan pembayaran yang paling umum, jadi perubahannya wajib berjejak:
 * nilai lama, nilai baru, alasannya, dan siapa yang mengubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pengajuan_pembayaran', function (Blueprint $table) {
            $table->string('bank_tujuan')->nullable()->after('referensi');
            $table->string('no_rekening_tujuan')->nullable()->after('bank_tujuan');
            $table->string('atas_nama_tujuan')->nullable()->after('no_rekening_tujuan');
        });

        Schema::create('pengajuan_rekening_riwayat', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_pengajuan');
            $table->string('bank_lama')->nullable();
            $table->string('no_rekening_lama')->nullable();
            $table->string('atas_nama_lama')->nullable();
            $table->string('bank_baru')->nullable();
            $table->string('no_rekening_baru')->nullable();
            $table->string('atas_nama_baru')->nullable();
            $table->string('alasan');
            $table->unsignedInteger('id_pengguna');
            $table->timestamp('created_at')->nullable();

            $table->foreign('id_pengajuan')->references('id')->on('pengajuan_pembayaran')->cascadeOnDelete();
            $table->index('id_pengajuan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengajuan_rekening_riwayat');
        Schema::table('pengajuan_pembayaran', function (Blueprint $table) {
            $table->dropColumn(['bank_tujuan', 'no_rekening_tujuan', 'atas_nama_tujuan']);
        });
    }
};
