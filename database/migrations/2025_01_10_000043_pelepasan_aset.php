<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PELEPASAN ASET TETAP.
 *
 * Sebelum ini aset hanya bisa DIHAPUS dari daftar: barisnya lenyap, sementara
 * nilai perolehan dan akumulasi penyusutannya tetap utuh di buku besar. Register
 * aset dan buku besar bercerai diam-diam, dan tak ada modul mana pun yang bisa
 * mencatat aset yang dijual, dihibahkan, atau rusak total.
 *
 * Satu dokumen, tiga perlakuan, satu rumus jurnal:
 *
 *   Kas masuk (harga jual, 0 bila bukan penjualan)   D
 *   Akumulasi Penyusutan                              D
 *   Aset Tetap (nilai perolehan)                                K
 *   Selisihnya → Laba/Rugi Pelepasan                  D atau K
 *
 * Hibah & penghapusan hanyalah penjualan berharga nol: selisihnya persis
 * sebesar nilai buku, dan jatuh ke sisi debet sebagai beban. Karena itu tak
 * perlu tiga cabang kode — satu rumus melayani ketiganya.
 *
 * Aset yang sudah dilepas berstatus `dilepas`, dan `runDepreciation()` memang
 * sudah menyaring `status = aktif` saja — jadi penyusutannya berhenti sendiri.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pelepasan_aset', function (Blueprint $table) {
            $table->increments('id');
            $table->string('nomor_ref')->unique();   // PLA-YYMM-NNNN
            $table->string('kode_aset');
            $table->date('tanggal');
            $table->enum('perlakuan', ['dijual', 'dihibahkan', 'dihapuskan']);

            // Hanya terisi pada penjualan. Untuk hibah & penghapusan nilainya 0,
            // dan rumus jurnalnya tetap sama.
            $table->decimal('harga_jual', 18, 2)->default(0);
            $table->string('kode_rekening')->nullable();

            $table->string('kode_coa_akumulasi');
            $table->string('kode_coa_labarugi');
            $table->string('kode_unit')->nullable();
            $table->string('kode_bagian')->nullable();

            // Potret keadaan aset SAAT dilepas. Master asetnya masih ada dan
            // masih bisa berubah; dokumen ini harus tetap bisa menjelaskan
            // dirinya sendiri bertahun-tahun kemudian.
            $table->decimal('nilai_perolehan', 18, 2);
            $table->decimal('akumulasi', 18, 2);
            $table->decimal('nilai_buku', 18, 2);
            $table->decimal('laba_rugi', 18, 2);

            $table->text('alasan');
            $table->enum('status', ['aktif', 'void'])->default('aktif');
            $table->unsignedInteger('journal_entry_id')->nullable();
            $table->unsignedInteger('id_pengguna')->nullable();
            $table->string('void_reason')->nullable();
            $table->string('void_by')->nullable();
            $table->timestamp('void_at')->nullable();
            $table->timestamps();

            $table->foreign('kode_aset')->references('kode_aset')->on('assets')->restrictOnDelete();
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->nullOnDelete();
            $table->foreign('id_pengguna')->references('id_pengguna')->on('users')->nullOnDelete();
            $table->index('kode_aset');
            $table->index('status');
        });

        // Satu aset hanya boleh punya SATU pelepasan yang hidup. Tanpa ini,
        // dua petugas bisa melepas aset yang sama dan buku besar kehilangan
        // nilainya dua kali.
        Schema::getConnection()->statement(
            "CREATE UNIQUE INDEX pelepasan_aset_hidup ON pelepasan_aset (kode_aset) WHERE status = 'aktif'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('pelepasan_aset');
    }
};
