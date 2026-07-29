<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PINJAMAN KARYAWAN — piutang kepada pegawai, dengan jadwal termin dan DUA
 * jalur pelunasan.
 *
 * `termin_pinjaman_karyawan` adalah KESEPAKATAN JADWAL, bukan transaksi: ia
 * tidak berjurnal dan tidak berstatus lunas per baris. Yang mengurangi hutang
 * adalah `pembayaran_pinjaman_karyawan`. Pola ini menyalin rencana angsuran
 * uang pangkal, yang sudah terbukti tidak membingungkan: Σ termin = pokok.
 *
 * Dua cara bayar dibedakan di kolom `cara` karena jurnalnya memang berbeda:
 *   tunai       → Debit kas/rekening        / Kredit Piutang Karyawan
 *   potong_gaji → Debit akun beban gaji     / Kredit Piutang Karyawan  (tanpa kas)
 * Pada potong gaji, gaji dibayarkan NETTO lewat Kas Keluar, sehingga gabungan
 * kedua jurnal itu menghasilkan beban gaji penuh dan kas berkurang sebesar netto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pinjaman_karyawan', function (Blueprint $table) {
            $table->increments('id');
            $table->string('nomor')->unique(); // PKJ-YYMM-NNNN
            $table->string('kode_karyawan');
            $table->date('tanggal');
            $table->decimal('pokok', 18, 2);
            $table->decimal('terbayar', 18, 2)->default(0);
            $table->string('kode_coa_piutang');           // akun piutang karyawan
            $table->string('kode_rekening')->nullable();  // kas sumber pencairan
            $table->string('status')->default('aktif');   // aktif | lunas | void
            $table->text('keterangan')->nullable();
            $table->unsignedInteger('journal_entry_id')->nullable(); // jurnal pencairan
            $table->unsignedInteger('id_pengguna')->nullable();
            $table->string('void_reason')->nullable();
            $table->timestamps();

            $table->foreign('kode_karyawan')->references('kode')->on('karyawan')->restrictOnDelete();
            $table->index('status');
            $table->index('kode_karyawan');
        });

        Schema::create('termin_pinjaman_karyawan', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_pinjaman');
            $table->integer('urutan');
            $table->decimal('nominal', 18, 2);
            $table->date('jatuh_tempo');
            $table->text('keterangan')->nullable();

            $table->foreign('id_pinjaman')->references('id')->on('pinjaman_karyawan')->cascadeOnDelete();
            $table->index(['id_pinjaman', 'urutan']);
        });

        Schema::create('pembayaran_pinjaman_karyawan', function (Blueprint $table) {
            $table->increments('id');
            $table->string('nomor')->unique(); // BPK-YYMM-NNNN
            $table->unsignedInteger('id_pinjaman');
            $table->date('tanggal');
            $table->decimal('nominal', 18, 2);
            $table->string('cara');                        // tunai | potong_gaji
            $table->string('kode_rekening')->nullable();   // wajib bila tunai
            $table->string('kode_coa_lawan')->nullable();  // wajib bila potong gaji (beban gaji)
            $table->text('keterangan')->nullable();
            $table->unsignedInteger('journal_entry_id')->nullable();
            $table->unsignedInteger('id_pengguna')->nullable();
            $table->timestamps();

            $table->foreign('id_pinjaman')->references('id')->on('pinjaman_karyawan')->cascadeOnDelete();
            $table->index('id_pinjaman');
            $table->index('cara');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pembayaran_pinjaman_karyawan');
        Schema::dropIfExists('termin_pinjaman_karyawan');
        Schema::dropIfExists('pinjaman_karyawan');
    }
};
