<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * cash_out — Kas Keluar (Payment Voucher, multi-baris). kode_unit NULL = header
 * tidak menentukan unit (lihat barisnya) — onDelete RESTRICT. id_bank_loan
 * (opsional) → angsuran pinjaman: baris akun Hutang Bank = pembayaran pokok.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_out', function (Blueprint $table) {
            $table->increments('kode_transaksi');
            $table->string('nomor_transaksi')->unique();
            $table->date('tanggal');
            $table->string('kode_unit')->nullable();
            $table->string('kode_rekening'); // -> bank_accounts.kode_coa
            $table->string('kode_vendor')->nullable();
            $table->string('referensi')->nullable();
            $table->text('keterangan')->nullable();
            $table->decimal('nominal', 18, 2);
            $table->unsignedInteger('id_bank_loan')->nullable();
            $table->enum('status', ['aktif', 'void'])->default('aktif');
            $table->string('void_reason')->nullable();
            $table->string('void_by')->nullable();
            $table->timestamp('void_at')->nullable();
            $table->unsignedInteger('id_pengguna');
            $table->timestamps();

            $table->foreign('kode_unit')->references('kode_unit')->on('business_units')->restrictOnDelete();
            $table->foreign('kode_rekening')->references('kode_coa')->on('bank_accounts');
            $table->foreign('kode_vendor')->references('kode_vendor')->on('vendors');
            $table->foreign('id_bank_loan')->references('id')->on('bank_loans');
            $table->foreign('id_pengguna')->references('id_pengguna')->on('users');
            $table->index('kode_unit');
            $table->index('kode_rekening');
            $table->index('id_bank_loan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_out');
    }
};
