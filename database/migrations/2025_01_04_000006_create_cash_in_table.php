<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** cash_in — Kas Masuk (Receivable Voucher, multi-baris). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_in', function (Blueprint $table) {
            $table->increments('kode_transaksi');
            $table->string('nomor_transaksi')->unique();
            $table->date('tanggal');
            $table->string('kode_unit');
            $table->string('kode_rekening'); // -> bank_accounts.kode_coa
            $table->string('kode_customer')->nullable();
            $table->string('referensi')->nullable();
            $table->text('keterangan')->nullable();
            $table->decimal('nominal', 18, 2);
            $table->enum('status', ['aktif', 'void'])->default('aktif');
            $table->string('void_reason')->nullable();
            $table->string('void_by')->nullable();
            $table->timestamp('void_at')->nullable();
            $table->unsignedInteger('id_pengguna');
            $table->timestamps();

            $table->foreign('kode_unit')->references('kode_unit')->on('business_units');
            $table->foreign('kode_rekening')->references('kode_coa')->on('bank_accounts');
            $table->foreign('kode_customer')->references('kode_customer')->on('customers');
            $table->foreign('id_pengguna')->references('id_pengguna')->on('users');
            $table->index('kode_unit');
            $table->index('kode_rekening');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_in');
    }
};
