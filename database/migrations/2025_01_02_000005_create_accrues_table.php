<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * accrues — Jurnal akrual/penyesuaian (debet & kredit dipilih langsung).
 * kode_coa_debet/kredit disimpan sebagai string ber-index (validasi di service,
 * tanpa FK — sesuai konvensi field pemilih-akun). status: aktif | reversed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accrues', function (Blueprint $table) {
            $table->increments('id_accrue');
            $table->date('tanggal');
            $table->string('periode')->nullable(); // mis. "2026-07"
            $table->string('kode_coa_debet');
            $table->string('nama_coa_debet');
            $table->string('kode_coa_kredit');
            $table->string('nama_coa_kredit');
            $table->decimal('nominal', 18, 2);
            $table->string('kode_unit')->nullable();
            $table->string('nomor_referensi')->nullable(); // ACC-YYMM-NNNN
            $table->text('keterangan')->nullable();
            $table->string('status')->default('aktif'); // aktif | reversed
            $table->unsignedInteger('id_pengguna');
            $table->timestamps();

            $table->foreign('kode_unit')->references('kode_unit')->on('business_units');
            $table->foreign('id_pengguna')->references('id_pengguna')->on('users');
            $table->index('kode_coa_debet');
            $table->index('kode_coa_kredit');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accrues');
    }
};
