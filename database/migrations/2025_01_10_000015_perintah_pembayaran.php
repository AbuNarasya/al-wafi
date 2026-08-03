<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PERINTAH PEMBAYARAN (PP) — dokumen KAS, bukan dokumen akuntansi.
 *
 * Ia mengumpulkan kewajiban yang sudah diakui di dokumen lain, meminta otorisasi
 * satu pejabat atas WAKTU & METODE pembayarannya, lalu direalisasikan lewat Kas
 * Keluar. PP sendiri TIDAK PERNAH MENJURNAL apa pun — uang tetap tercatat sekali
 * saja, di Kas Keluar. Itulah yang membuat modul ini tak bisa merusak laporan
 * keuangan: kesalahan terburuknya hanya perintah yang tak pernah dieksekusi.
 *
 * Tiga tabel:
 *  • perintah_pembayaran            — kepala dokumen + dua keputusan (otorisasi & penutupan)
 *  • perintah_pembayaran_detail     — barisnya, menunjuk kewajiban yang SUDAH ADA
 *  • akun_pengurang_dana_bebas      — pengaturan: akun mana yang mengurangi saldo terpakai
 *
 * Plus dua kolom penaut di Kas Keluar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perintah_pembayaran', function (Blueprint $table) {
            $table->increments('kode_transaksi');
            $table->string('nomor')->unique(); // PP-YYMM-NNNN
            $table->date('tanggal');
            $table->text('keterangan');

            // Usulan penyusun vs penetapan pejabat — sengaja dua kolom terpisah
            // supaya terlihat bila pejabat menggeser tanggalnya.
            $table->date('tanggal_usulan')->nullable();
            $table->date('tanggal_bayar')->nullable();
            $table->string('metode')->nullable(); // transfer | teller | tunai
            $table->string('kode_rekening_rencana')->nullable(); // rencana; realisasi boleh beda

            $table->decimal('total_diajukan', 18, 2)->default(0);
            $table->decimal('total_diotorisasi', 18, 2)->default(0);

            // draf → menunggu → diotorisasi → sebagian → selesai; ditolak · ditutup
            $table->string('status')->default('draf');

            $table->unsignedInteger('disusun_oleh');
            $table->unsignedInteger('diotorisasi_oleh')->nullable();
            $table->timestamp('diotorisasi_pada')->nullable();
            $table->text('catatan_otorisasi')->nullable();
            $table->text('alasan_tolak')->nullable();

            // PENUTUPAN — keputusan kedua, sejajar otorisasi. PP tak pernah
            // menutup sendiri: harus ada satu titik yang bisa disebut "tuntas",
            // supaya "kenapa sisanya tak dibayar?" punya jawaban tertulis.
            $table->unsignedInteger('ditutup_oleh')->nullable();
            $table->timestamp('ditutup_pada')->nullable();
            $table->text('alasan_tutup')->nullable();

            $table->timestamps();

            $table->foreign('kode_rekening_rencana')->references('kode_coa')->on('bank_accounts')->nullOnDelete();
            $table->foreign('disusun_oleh')->references('id_pengguna')->on('users')->restrictOnDelete();
            $table->index('status');
            $table->index('tanggal_bayar');
        });

        Schema::create('perintah_pembayaran_detail', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('kode_transaksi');

            // Kewajiban yang DITUNJUK — bukan diciptakan. Tak ada tipe "lainnya":
            // yang belum berdokumen harus jadi Pengajuan Pembayaran lebih dulu,
            // sehingga tiap rupiah yang dibayar punya dokumen di belakangnya.
            $table->string('sumber'); // pengajuan | invoice | uang_muka | bank_loan
            $table->unsignedInteger('id_dokumen');
            $table->string('nomor_dokumen');   // snapshot, agar riwayat tetap terbaca
            $table->string('pihak')->nullable();
            $table->text('keterangan')->nullable();
            $table->string('kode_unit')->nullable();
            $table->date('jatuh_tempo')->nullable();

            $table->decimal('nominal_diajukan', 18, 2);
            $table->decimal('nominal_diotorisasi', 18, 2)->default(0);
            $table->decimal('terbayar', 18, 2)->default(0);
            $table->decimal('sisa', 18, 2)->default(0);

            // diajukan → disetujui | ditunda | batal (saat PP ditutup)
            $table->string('status_baris')->default('diajukan');
            $table->text('alasan')->nullable(); // alasan ditunda / dikurangi — muncul di riwayat PP berikutnya
            $table->boolean('ditambahkan_pengotorisasi')->default(false);

            $table->timestamps();

            $table->foreign('kode_transaksi')->references('kode_transaksi')->on('perintah_pembayaran')->cascadeOnDelete();
            $table->foreign('kode_unit')->references('kode_unit')->on('business_units')->nullOnDelete();
            $table->index(['sumber', 'id_dokumen']);
        });

        // ANTI BAYAR-DOBEL. Satu kewajiban hanya boleh berada di SATU baris yang
        // masih hidup. Indeks parsial: baris yang sudah batal/ditunda tak lagi
        // mengunci, sehingga kewajibannya bebas diajukan di PP berikutnya.
        DB::statement("
            CREATE UNIQUE INDEX perintah_pembayaran_kewajiban_hidup
            ON perintah_pembayaran_detail (sumber, id_dokumen)
            WHERE status_baris IN ('diajukan', 'disetujui')
        ");

        Schema::create('akun_pengurang_dana_bebas', function (Blueprint $table) {
            $table->increments('id');
            $table->string('kode_coa')->unique();
            $table->timestamps();

            $table->foreign('kode_coa')->references('kode_coa')->on('coa_detail')->cascadeOnDelete();
        });

        Schema::table('cash_out', function (Blueprint $table) {
            $table->unsignedInteger('id_perintah')->nullable()->after('id_bank_loan');
            $table->foreign('id_perintah')->references('kode_transaksi')->on('perintah_pembayaran')->nullOnDelete();
            $table->index('id_perintah');
        });

        Schema::table('cash_out_details', function (Blueprint $table) {
            $table->unsignedInteger('id_perintah_detail')->nullable()->after('id_pengajuan');
            $table->foreign('id_perintah_detail')->references('id')->on('perintah_pembayaran_detail')->nullOnDelete();
            $table->index('id_perintah_detail');
        });
    }

    public function down(): void
    {
        Schema::table('cash_out_details', function (Blueprint $table) {
            $table->dropForeign(['id_perintah_detail']);
            $table->dropColumn('id_perintah_detail');
        });
        Schema::table('cash_out', function (Blueprint $table) {
            $table->dropForeign(['id_perintah']);
            $table->dropColumn('id_perintah');
        });
        Schema::dropIfExists('akun_pengurang_dana_bebas');
        Schema::dropIfExists('perintah_pembayaran_detail');
        Schema::dropIfExists('perintah_pembayaran');
    }
};
